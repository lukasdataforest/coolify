<?php

namespace App\Services;

use App\Exceptions\RateLimitException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Client for the dataforest Cloud public API (https://api.dataforest.net/docs).
 *
 * dataforest calls a virtual machine a "seed". A seed is ordered with a plan reference
 * (lines/{line}/models/{model}), a region slug, an image version ref, and a project of the
 * team the token belongs to.
 */
class DataforestService
{
    private string $baseUrl = 'https://api.dataforest.net/api/v1/public';

    public function __construct(private string $token) {}

    private function request(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(
                $method === 'get' ? 3 : 1,
                fn (int $attempt) => $attempt * 200,
                fn (\Exception $exception) => ! $exception instanceof RequestException || $exception->response->serverError(),
                throw: false
            )
            ->{$method}($this->baseUrl.$endpoint, $data);

        if (! $response->successful()) {
            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');
                if ($retryAfter === null || $retryAfter === '') {
                    $resetTime = $response->header('X-RateLimit-Reset');
                    $retryAfter = $resetTime ? max(0, (int) $resetTime - time()) : null;
                }

                throw new RateLimitException(
                    'Rate limit exceeded. Please try again later.',
                    $retryAfter !== null ? (int) $retryAfter : null
                );
            }

            throw new \Exception('dataforest API error: '.$this->errorMessage($response->json()), $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Flatten an api.ErrorResponse into one line, keeping the per-field details of a 422.
     */
    private function errorMessage(mixed $body): string
    {
        $message = data_get($body, 'message') ?? data_get($body, 'code') ?? 'Unknown error';

        $details = collect(data_get($body, 'details') ?? [])
            ->map(fn ($detail) => trim(implode(' ', array_filter([
                data_get($detail, 'field'),
                data_get($detail, 'message'),
            ]))))
            ->filter()
            ->join('; ');

        return $details === '' ? $message : "{$message} ({$details})";
    }

    /**
     * Walk a paginated collection endpoint and return every item of $resourceKey.
     */
    private function requestPaginated(string $endpoint, string $resourceKey, array $query = []): array
    {
        $allResults = [];
        $page = 1;

        do {
            $response = $this->request('get', $endpoint, array_merge($query, [
                'page' => $page,
                'limit' => 100,
            ]));

            $allResults = array_merge($allResults, $response[$resourceKey] ?? []);

            $totalPages = (int) data_get($response, 'meta.total_pages', 1);
            $page++;
        } while ($page <= $totalPages && $totalPages > 0);

        return $allResults;
    }

    /**
     * Team info with the projects a seed can be created in and the team's resource limits.
     */
    public function getTeam(): array
    {
        return $this->request('get', '/team');
    }

    /**
     * Projects of the team. A seed always belongs to exactly one of them.
     */
    public function getProjects(): array
    {
        return $this->getTeam()['projects'] ?? [];
    }

    /**
     * Regions (datacenters) that are open for new seeds.
     */
    public function getRegions(): array
    {
        return collect($this->requestPaginated('/regions', 'data'))
            ->filter(fn (array $region) => ($region['is_active'] ?? $region['active'] ?? true) === true)
            ->sortBy(fn (array $region) => $region['name'] ?? $region['slug'] ?? '')
            ->values()
            ->all();
    }

    /**
     * One region with its product lines, plan models, and OS images.
     *
     * With $checkAvailability the response carries a `capacity` block per model and an `ipv4`
     * block for the region, so the form can hide what cannot be ordered right now.
     */
    public function getRegion(string $slug, bool $checkAvailability = false, string $locale = 'en'): array
    {
        $query = ['locale' => $locale];
        if ($checkAvailability) {
            $query['check_availability'] = 'true';
        }

        return $this->request('get', '/regions/'.rawurlencode($slug), $query)['data'] ?? [];
    }

    /**
     * Orderable plans of a region, flattened over its product lines.
     *
     * Each entry carries the `plan` reference POST /seeds expects, plus the line it came from
     * and the hardware and price of the model.
     *
     * @return list<array<string, mixed>>
     */
    public function getRegionPlans(array $region): array
    {
        return collect($region['lines'] ?? [])
            ->flatMap(function (array $line): array {
                return collect($line['models'] ?? [])
                    ->filter(fn (array $model) => ($model['is_selectable'] ?? true) === true)
                    ->filter(fn (array $model) => ! isset($model['capacity']) || ($model['capacity']['capacity_status'] ?? null) === 'available')
                    ->map(fn (array $model): array => [
                        'plan' => "lines/{$line['slug']}/models/{$model['slug']}",
                        'line_slug' => $line['slug'] ?? null,
                        'line_name' => $line['name'] ?? $line['slug'] ?? null,
                        'class' => $line['class'] ?? null,
                        'slug' => $model['slug'] ?? null,
                        'name' => $model['name'] ?? $model['slug'] ?? null,
                        'cpu' => $model['cpu'] ?? null,
                        'memory' => $model['memory'] ?? null,
                        'storage' => $model['storage'] ?? null,
                        'gpu' => $model['gpu'] ?? null,
                        'gross_max_price_per_month' => $model['gross_max_price_per_month'] ?? null,
                        'max_price_per_month' => $model['max_price_per_month'] ?? null,
                        'gross_max_ipv4_price_per_month' => $model['gross_max_ipv4_price_per_month'] ?? null,
                        'gross_max_backup_price_per_month' => $model['gross_max_backup_price_per_month'] ?? null,
                    ])
                    ->all();
            })
            ->sort(fn (array $a, array $b): int => [$a['cpu'] ?? 0, $a['memory'] ?? 0, $a['storage'] ?? 0]
                <=> [$b['cpu'] ?? 0, $b['memory'] ?? 0, $b['storage'] ?? 0])
            ->values()
            ->all();
    }

    /**
     * Installable OS image versions of a region, flattened over its images.
     *
     * `ref` is what POST /seeds expects as `source.ref`. GPU-only images are left out unless the
     * chosen plan has a GPU.
     *
     * @return list<array<string, mixed>>
     */
    public function getRegionImageVersions(array $region, bool $includeGpuImages = false): array
    {
        return collect($region['images'] ?? [])
            ->filter(fn (array $image) => ($image['is_selectable'] ?? true) === true)
            ->filter(fn (array $image) => $includeGpuImages || ($image['hardware'] ?? 'generic') !== 'gpu')
            ->flatMap(function (array $image): array {
                return collect($image['versions'] ?? [])
                    ->filter(fn (array $version) => ($version['is_selectable'] ?? true) === true)
                    ->map(fn (array $version): array => [
                        'ref' => self::imageVersionRef($image, $version),
                        'image_name' => $image['name'] ?? $image['slug'] ?? null,
                        'image_slug' => $image['slug'] ?? null,
                        'hardware' => $image['hardware'] ?? 'generic',
                        'version' => $version['version'] ?? null,
                        'display_name' => $version['display_name'] ?? $version['version'] ?? $version['slug'] ?? null,
                    ])
                    ->filter(fn (array $version) => filled($version['ref']))
                    ->all();
            })
            // Images by name, newest version of each first.
            ->sort(fn (array $a, array $b): int => strcasecmp((string) $a['image_name'], (string) $b['image_name'])
                ?: strnatcasecmp((string) $b['display_name'], (string) $a['display_name']))
            ->values()
            ->all();
    }

    /**
     * The `source.ref` POST /seeds expects for an image version.
     *
     * The catalog returns the bare version slug (debian-v13), while a seed reports its
     * `image_version` as the full reference (images/debian/versions/debian-v13) — and that is the
     * form the create endpoint accepts. Compose it, but pass a full reference through untouched in
     * case the catalog starts returning one.
     */
    private static function imageVersionRef(array $image, array $version): ?string
    {
        $versionSlug = $version['slug'] ?? null;
        $imageSlug = $image['slug'] ?? null;

        if (blank($versionSlug)) {
            return null;
        }

        if (str_starts_with($versionSlug, 'images/')) {
            return $versionSlug;
        }

        return blank($imageSlug) ? null : "images/{$imageSlug}/versions/{$versionSlug}";
    }

    /**
     * SSH keys on the team's key ring.
     */
    public function getSshKeys(): array
    {
        return $this->request('get', '/sshkeys')['ssh_keys'] ?? [];
    }

    public function createSshKey(string $name, string $publicKey): array
    {
        return $this->request('post', '/sshkeys', [
            'name' => $name,
            'public_key' => $publicKey,
        ]);
    }

    /**
     * The id of $publicKey on the team's key ring, uploading it first if it is not there yet.
     *
     * Keys are matched on the key body, the one part of the line that a key is identified by;
     * the comment behind it differs between accounts for the very same key.
     */
    public function ensureSshKey(string $name, string $publicKey): string
    {
        $body = self::publicKeyBody($publicKey);

        foreach ($this->getSshKeys() as $sshKey) {
            if ($body !== null && self::publicKeyBody((string) ($sshKey['public_key'] ?? '')) === $body) {
                return (string) $sshKey['id'];
            }
        }

        $created = $this->createSshKey(self::sshKeyName($name), $publicKey);

        if (blank($created['id'] ?? null)) {
            throw new \Exception('dataforest did not return an id for the uploaded SSH key.');
        }

        return (string) $created['id'];
    }

    /**
     * dataforest wants an SSH key name of 3 to 255 characters; Coolify's key names are free-form.
     */
    private static function sshKeyName(string $name): string
    {
        $name = trim($name);

        return mb_strlen($name) < 3
            ? trim('coolify '.$name)
            : mb_substr($name, 0, 255);
    }

    /**
     * The base64 body of an SSH public key line, without its type prefix and comment.
     */
    private static function publicKeyBody(string $publicKey): ?string
    {
        $parts = preg_split('/\s+/', trim($publicKey)) ?: [];

        return $parts[1] ?? null;
    }

    /**
     * Order a seed. The API answers 202 with the seed; it is provisioned in the background.
     *
     * $idempotencyKey makes the order safely repeatable, so a timeout on our side cannot buy a
     * second seed.
     */
    public function createSeed(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request(
            'post',
            '/seeds',
            $params,
            $idempotencyKey ? ['Idempotency-Key' => $idempotencyKey] : []
        );
    }

    public function getSeed(string $seedId): array
    {
        return $this->request('get', '/seeds/'.rawurlencode($seedId));
    }

    public function getSeeds(): array
    {
        return $this->requestPaginated('/seeds', 'seeds');
    }

    public function deleteSeed(string $seedId): array
    {
        return $this->request('delete', '/seeds/'.rawurlencode($seedId));
    }

    /**
     * Run a seed action, for example seed.start or seed.reboot.
     */
    public function runSeedAction(string $seedId, string $type, array $params = []): array
    {
        return $this->request('post', '/seeds/'.rawurlencode($seedId).'/actions', array_merge($params, [
            'type' => $type,
        ]));
    }

    public function startSeed(string $seedId): array
    {
        return $this->runSeedAction($seedId, 'seed.start');
    }

    /**
     * The address to reach the seed on, preferring IPv4.
     *
     * For IPv6 the seed carries the /64 network in `address`; `primary_ip` is the address that is
     * actually configured on the guest, so it is the one Coolify connects to.
     */
    public function getPublicIpAddress(array $seed): ?string
    {
        foreach (['ipv4', 'ipv6'] as $version) {
            foreach ($seed[$version] ?? [] as $ipAddress) {
                $address = $ipAddress['primary_ip'] ?? $ipAddress['address'] ?? null;
                if (filled($address)) {
                    return $address;
                }
            }
        }

        return null;
    }

    /**
     * Briefly wait for the seed to get an address. Provisioning usually takes longer, so the
     * cloud provider status check backfills the IP later.
     */
    public function waitForPublicIp(array $seed, int $attempts = 10, int $sleepMilliseconds = 1000): array
    {
        if ($this->getPublicIpAddress($seed) || empty($seed['id'])) {
            return $seed;
        }

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            usleep($sleepMilliseconds * 1000);

            $seed = $this->getSeed((string) $seed['id']);

            if ($this->getPublicIpAddress($seed)) {
                return $seed;
            }
        }

        return $seed;
    }

    public function findSeedByIp(string $ip): ?array
    {
        $ip = normalizeIpAddress($ip);

        foreach ($this->getSeeds() as $seed) {
            foreach (['ipv4', 'ipv6'] as $version) {
                foreach ($seed[$version] ?? [] as $ipAddress) {
                    foreach (['primary_ip', 'address'] as $field) {
                        $candidate = $ipAddress[$field] ?? null;
                        if (filled($candidate) && normalizeIpAddress($candidate) === $ip) {
                            return $seed;
                        }
                    }
                }
            }
        }

        return null;
    }
}
