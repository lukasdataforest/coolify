<?php

namespace App\Http\Controllers\Api;

use App\Actions\Server\ValidateServer;
use App\Enums\ProxyTypes;
use App\Exceptions\RateLimitException;
use App\Http\Controllers\Api\Concerns\RequiresDeployAbility;
use App\Http\Controllers\Controller;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidCloudInitYaml;
use App\Rules\ValidHostname;
use App\Services\DataforestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class DataforestController extends Controller
{
    use RequiresDeployAbility;

    /**
     * dataforest caps a seed name at 50 characters.
     */
    private const MAX_SEED_NAME_LENGTH = 50;

    #[OA\Get(
        path: '/dataforest/projects',
        operationId: 'get-dataforest-projects',
        summary: 'Get dataforest team projects',
        description: 'A seed belongs to one project of the team the token belongs to.',
        security: [['bearerAuth' => []]],
        tags: ['dataforest'],
        responses: [
            new OA\Response(response: 200, description: 'List of dataforest team projects.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'dataforest denied access to this resource for the cloud provider token.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
            new OA\Response(response: 429, description: 'dataforest rate limit exceeded.'),
        ]
    )]
    public function projects(Request $request): JsonResponse
    {
        return $this->providerData($request, fn (DataforestService $service) => $service->getProjects(), 'projects');
    }

    #[OA\Get(
        path: '/dataforest/regions',
        operationId: 'get-dataforest-regions',
        summary: 'Get dataforest regions',
        security: [['bearerAuth' => []]],
        tags: ['dataforest'],
        responses: [
            new OA\Response(response: 200, description: 'List of dataforest regions.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'dataforest denied access to this resource for the cloud provider token.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
            new OA\Response(response: 429, description: 'dataforest rate limit exceeded.'),
        ]
    )]
    public function regions(Request $request): JsonResponse
    {
        return $this->providerData($request, fn (DataforestService $service) => $service->getRegions(), 'regions');
    }

    #[OA\Get(
        path: '/dataforest/plans',
        operationId: 'get-dataforest-plans',
        summary: 'Get the orderable dataforest plans of a region',
        description: 'Each entry carries the `plan` reference that POST /servers/dataforest expects.',
        security: [['bearerAuth' => []]],
        tags: ['dataforest'],
        parameters: [
            new OA\Parameter(name: 'location', in: 'query', required: true, description: 'Region slug, for example fra01.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of dataforest plans in the region.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'dataforest denied access to this resource for the cloud provider token.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
            new OA\Response(response: 429, description: 'dataforest rate limit exceeded.'),
        ]
    )]
    public function plans(Request $request): JsonResponse
    {
        return $this->regionData($request, fn (DataforestService $service, array $region) => $service->getRegionPlans($region), 'plans');
    }

    #[OA\Get(
        path: '/dataforest/images',
        operationId: 'get-dataforest-images',
        summary: 'Get the installable dataforest image versions of a region',
        description: 'Each entry carries the `ref` that POST /servers/dataforest expects as `image`.',
        security: [['bearerAuth' => []]],
        tags: ['dataforest'],
        parameters: [
            new OA\Parameter(name: 'location', in: 'query', required: true, description: 'Region slug, for example fra01.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of dataforest image versions in the region.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'dataforest denied access to this resource for the cloud provider token.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
            new OA\Response(response: 429, description: 'dataforest rate limit exceeded.'),
        ]
    )]
    public function images(Request $request): JsonResponse
    {
        return $this->regionData(
            $request,
            fn (DataforestService $service, array $region) => $service->getRegionImageVersions($region, includeGpuImages: true),
            'images'
        );
    }

    #[OA\Get(
        path: '/dataforest/ssh-keys',
        operationId: 'get-dataforest-ssh-keys',
        summary: 'Get the SSH keys on the dataforest team key ring',
        security: [['bearerAuth' => []]],
        tags: ['dataforest'],
        responses: [
            new OA\Response(response: 200, description: 'List of dataforest SSH keys.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'dataforest denied access to this resource for the cloud provider token.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
            new OA\Response(response: 429, description: 'dataforest rate limit exceeded.'),
        ]
    )]
    public function sshKeys(Request $request): JsonResponse
    {
        return $this->providerData($request, fn (DataforestService $service) => $service->getSshKeys(), 'SSH keys');
    }

    #[OA\Post(
        path: '/servers/dataforest',
        operationId: 'create-dataforest-server',
        summary: 'Create a dataforest seed and a linked server',
        security: [['bearerAuth' => []]],
        tags: ['dataforest'],
        responses: [
            new OA\Response(response: 201, description: 'dataforest seed created and linked to a Coolify server.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token needs the deploy permission for a cloud-init script or extra SSH keys.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
            new OA\Response(response: 429, description: 'dataforest rate limit exceeded.'),
        ]
    )]
    public function createServer(Request $request): JsonResponse
    {
        $allowedFields = [
            'cloud_provider_token_uuid',
            'cloud_provider_token_id',
            'project_id',
            'location',
            'plan',
            'image',
            'name',
            'private_key_uuid',
            'enable_ipv4',
            'enable_backups',
            'ssh_key_ids',
            'user_data',
            'instant_validate',
        ];
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', [Server::class]);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
            'project_id' => 'required|string',
            'location' => 'required|string',
            'plan' => 'required|string',
            'image' => 'required|string',
            'name' => ['nullable', 'string', 'min:3', 'max:'.self::MAX_SEED_NAME_LENGTH, new ValidHostname],
            'private_key_uuid' => 'required|string',
            'enable_ipv4' => 'nullable|boolean',
            'enable_backups' => 'nullable|boolean',
            'ssh_key_ids' => 'nullable|array',
            'ssh_key_ids.*' => 'string',
            'user_data' => ['nullable', 'string', new ValidCloudInitYaml],
            'instant_validate' => 'nullable|boolean',
        ]);
        $extraFields = array_diff(array_keys($request->all()), $allowedFields);

        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        if ($request->filled('user_data') || $request->array('ssh_key_ids')) {
            $forbidden = $this->deployAbilityForbiddenResponse($request, 'A cloud-init script or extra SSH keys grant root access on the new server, so it needs a token with the deploy permission.');
            if ($forbidden) {
                return $forbidden;
            }
        }

        $team = Team::find($teamId);
        if (Team::serverLimitReached($team)) {
            return response()->json(['message' => 'Server limit reached for your subscription.'], 400);
        }

        $token = $this->dataforestToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        $privateKey = PrivateKey::whereTeamId($teamId)->whereUuid($request->private_key_uuid)->first();
        if (! $privateKey) {
            return response()->json(['message' => 'Private key not found.'], 404);
        }

        $seedId = null;

        try {
            $dataforestService = new DataforestService($token->token);
            $normalizedServerName = strtolower(trim($request->name ?: Str::limit(generate_random_name(), self::MAX_SEED_NAME_LENGTH, '')));

            // The Coolify key must be on the seed, or Coolify cannot reach it over SSH.
            $sshKeyIds = array_values(array_unique(array_merge(
                [$dataforestService->ensureSshKey($privateKey->name, $privateKey->getPublicKey())],
                array_map('strval', $request->array('ssh_key_ids')),
            )));

            $params = [
                'name' => $normalizedServerName,
                'project_id' => $request->string('project_id')->toString(),
                'location' => $request->string('location')->toString(),
                'plan' => $request->string('plan')->toString(),
                'source' => [
                    'type' => 'image',
                    'ref' => $request->string('image')->toString(),
                ],
                'ssh_keys' => $sshKeyIds,
                'enable_ipv4' => $request->boolean('enable_ipv4', true),
                'backups' => $request->boolean('enable_backups'),
            ];

            if ($request->filled('user_data')) {
                $params['user_data'] = $request->string('user_data')->toString();
            }

            $seed = $dataforestService->createSeed($params, idempotencyKey: (string) Str::uuid());
            $seedId = (string) $seed['id'];

            $server = Server::create([
                'name' => $normalizedServerName,
                'ip' => Server::PLACEHOLDER_IP,
                'user' => 'root',
                'port' => 22,
                'team_id' => $teamId,
                'private_key_id' => $privateKey->id,
                'cloud_provider_token_id' => $token->id,
                'dataforest_seed_id' => $seedId,
                'dataforest_seed_status' => $seed['state'] ?? null,
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            try {
                $seed = $dataforestService->waitForPublicIp($seed);
                $ipAddress = $dataforestService->getPublicIpAddress($seed);
                if ($ipAddress) {
                    $server->update([
                        'ip' => $ipAddress,
                        'dataforest_seed_status' => $seed['state'] ?? $server->dataforest_seed_status,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            $ipAddress = $server->fresh()->ip;

            if ($request->boolean('instant_validate') && ! $server->hasPlaceholderIp()) {
                ValidateServer::dispatch($server);
            }

            auditLog('api.dataforest_seed.created', [
                'team_id' => $teamId,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'dataforest_seed_id' => $seedId,
                'ip' => $ipAddress,
            ]);

            return response()->json([
                'uuid' => $server->uuid,
                'dataforest_seed_id' => $seedId,
                'ip' => $ipAddress,
                'provisioning' => $ipAddress === Server::PLACEHOLDER_IP,
            ])->setStatusCode(201);
        } catch (RateLimitException $e) {
            return $this->rateLimitResponse($e);
        } catch (\Throwable $e) {
            logger()->error('Failed to create dataforest server', [
                'error' => $e->getMessage(),
                'team_id' => $teamId,
                'dataforest_seed_id' => $seedId,
            ]);

            return response()->json(array_filter([
                'message' => $seedId
                    ? 'The dataforest seed was created but could not be saved in Coolify. Manage it in the dataforest Cloud UI.'
                    : 'Failed to create dataforest server: '.$e->getMessage(),
                'dataforest_seed_id' => $seedId,
            ]), 500);
        }
    }

    /**
     * Answer a provider catalog that does not depend on a region.
     */
    private function providerData(Request $request, callable $callback, string $resource): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $token = $this->dataforestToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            return response()->json($callback(new DataforestService($token->token)));
        } catch (RateLimitException $e) {
            return $this->rateLimitResponse($e);
        } catch (\Throwable $e) {
            return $this->providerErrorResponse($e, $resource);
        }
    }

    /**
     * Answer a provider catalog that is per region; `location` says which one.
     */
    private function regionData(Request $request, callable $callback, string $resource): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'location' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = $this->dataforestToken($request, $teamId);
        if ($token instanceof JsonResponse) {
            return $token;
        }

        try {
            $service = new DataforestService($token->token);
            $region = $service->getRegion($request->string('location')->toString(), checkAvailability: true);

            return response()->json($callback($service, $region));
        } catch (RateLimitException $e) {
            return $this->rateLimitResponse($e);
        } catch (\Throwable $e) {
            return $this->providerErrorResponse($e, $resource);
        }
    }

    private function providerErrorResponse(\Throwable $e, string $resource): JsonResponse
    {
        $errorPrefix = 'dataforest API error: ';

        if (in_array($e->getCode(), [401, 403], true) && str_starts_with($e->getMessage(), $errorPrefix)) {
            $message = substr($e->getMessage(), strlen($errorPrefix));

            return response()->json(['message' => "dataforest denied access to {$resource}: {$message}"], 403);
        }

        if ((int) $e->getCode() === 404) {
            return response()->json(['message' => "dataforest {$resource} not found."], 404);
        }

        return response()->json(['message' => "Failed to fetch dataforest {$resource}."], 500);
    }

    private function rateLimitResponse(RateLimitException $e): JsonResponse
    {
        $response = response()->json(['message' => $e->getMessage()], 429);
        if ($e->retryAfter !== null) {
            $response->header('Retry-After', $e->retryAfter);
        }

        return $response;
    }

    private function dataforestToken(Request $request, int $teamId): CloudProviderToken|JsonResponse
    {
        $validator = customApiValidator($request->all(), [
            'cloud_provider_token_uuid' => 'required_without:cloud_provider_token_id|string',
            'cloud_provider_token_id' => 'required_without:cloud_provider_token_uuid|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUuid = $request->cloud_provider_token_uuid ?? $request->cloud_provider_token_id;
        $token = CloudProviderToken::whereTeamId($teamId)
            ->whereUuid($tokenUuid)
            ->where('provider', 'dataforest')
            ->first();

        if (! $token) {
            return response()->json(['message' => 'dataforest cloud provider token not found.'], 404);
        }

        $this->authorize('view', $token);

        return $token;
    }
}
