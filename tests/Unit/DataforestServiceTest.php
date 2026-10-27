<?php

use App\Exceptions\RateLimitException;
use App\Services\DataforestService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function dataforestRegionDetail(array $overrides = []): array
{
    return array_merge([
        'id' => '1',
        'name' => 'Frankfurt 1',
        'slug' => 'fra01',
        'city' => 'Frankfurt',
        'country' => 'Germany',
        'country_code' => 'DE',
        'is_active' => true,
        'ipv4' => ['capacity_status' => 'available'],
        'lines' => [
            [
                'slug' => 'entry',
                'name' => 'Entry',
                'class' => 'shared',
                'models' => [
                    [
                        'slug' => 'entry-c2-m4-s40',
                        'name' => 'Entry C2',
                        'cpu' => 2,
                        'memory' => 4,
                        'storage' => 40,
                        'is_selectable' => true,
                        'capacity' => ['capacity_status' => 'available'],
                        'gross_max_price_per_month' => '11.90',
                    ],
                    [
                        'slug' => 'entry-c1-m2-s20',
                        'name' => 'Entry C1',
                        'cpu' => 1,
                        'memory' => 2,
                        'storage' => 20,
                        'is_selectable' => true,
                        'capacity' => ['capacity_status' => 'available'],
                        'gross_max_price_per_month' => '5.90',
                    ],
                    [
                        'slug' => 'entry-c8-m32-s200',
                        'name' => 'Entry C8 (full)',
                        'cpu' => 8,
                        'memory' => 32,
                        'storage' => 200,
                        'is_selectable' => true,
                        'capacity' => ['capacity_status' => 'insufficient', 'capacity_reason' => 'ERR_NO_CAPACITY'],
                    ],
                ],
            ],
        ],
        'images' => [
            [
                'slug' => 'debian',
                'name' => 'Debian',
                'hardware' => 'generic',
                'is_selectable' => true,
                'versions' => [
                    ['slug' => 'debian-v11', 'display_name' => 'Debian 11', 'version' => '11', 'is_selectable' => false],
                    ['slug' => 'debian-v12', 'display_name' => 'Debian 12', 'version' => '12', 'is_selectable' => true],
                    ['slug' => 'debian-v13', 'display_name' => 'Debian 13', 'version' => '13', 'is_selectable' => true],
                ],
            ],
            [
                'slug' => 'cuda',
                'name' => 'Ubuntu CUDA',
                'hardware' => 'gpu',
                'is_selectable' => true,
                'versions' => [
                    ['slug' => 'cuda-v1', 'display_name' => 'CUDA 1', 'version' => '1', 'is_selectable' => true],
                ],
            ],
        ],
    ], $overrides);
}

it('lists only active regions', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/regions?*' => Http::response([
            'data' => [
                ['id' => '2', 'name' => 'Munich 1', 'slug' => 'muc01', 'is_active' => true],
                ['id' => '1', 'name' => 'Frankfurt 1', 'slug' => 'fra01', 'is_active' => true],
                ['id' => '3', 'name' => 'Retired 1', 'slug' => 'old01', 'is_active' => false],
            ],
            'meta' => ['page' => 1, 'limit' => 100, 'total' => 3, 'total_pages' => 1],
        ]),
    ]);

    expect(collect((new DataforestService('test-token'))->getRegions())->pluck('slug')->all())
        ->toBe(['fra01', 'muc01']);
});

it('follows region pagination', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/regions?page=1*' => Http::response([
            'data' => [['slug' => 'fra01', 'name' => 'Frankfurt 1', 'is_active' => true]],
            'meta' => ['page' => 1, 'limit' => 100, 'total' => 2, 'total_pages' => 2],
        ]),
        'https://api.dataforest.net/api/v1/public/regions?page=2*' => Http::response([
            'data' => [['slug' => 'muc01', 'name' => 'Munich 1', 'is_active' => true]],
            'meta' => ['page' => 2, 'limit' => 100, 'total' => 2, 'total_pages' => 2],
        ]),
    ]);

    expect((new DataforestService('test-token'))->getRegions())->toHaveCount(2);
});

it('builds plan references from the product lines of a region and drops what cannot be ordered', function () {
    $plans = (new DataforestService('test-token'))->getRegionPlans(dataforestRegionDetail());

    expect(collect($plans)->pluck('plan')->all())->toBe([
        'lines/entry/models/entry-c1-m2-s20',
        'lines/entry/models/entry-c2-m4-s40',
    ])
        ->and($plans[0]['line_name'])->toBe('Entry')
        ->and($plans[0]['cpu'])->toBe(1)
        ->and($plans[0]['gross_max_price_per_month'])->toBe('5.90');
});

it('builds the full source ref from the bare version slug the catalog returns', function () {
    $service = new DataforestService('test-token');

    // The catalog returns "debian-v13"; POST /seeds only accepts the full reference.
    expect(collect($service->getRegionImageVersions(dataforestRegionDetail()))->pluck('ref')->all())
        ->toBe([
            'images/debian/versions/debian-v13',
            'images/debian/versions/debian-v12',
        ]);

    expect(collect($service->getRegionImageVersions(dataforestRegionDetail(), includeGpuImages: true))->pluck('ref')->all())
        ->toContain('images/cuda/versions/cuda-v1');
});

it('leaves a version slug that is already a full reference untouched', function () {
    $region = dataforestRegionDetail([
        'images' => [[
            'slug' => 'debian',
            'name' => 'Debian',
            'hardware' => 'generic',
            'is_selectable' => true,
            'versions' => [
                ['slug' => 'images/debian/versions/debian-v13', 'display_name' => 'Debian 13', 'version' => '13', 'is_selectable' => true],
            ],
        ]],
    ]);

    expect(collect((new DataforestService('test-token'))->getRegionImageVersions($region))->pluck('ref')->all())
        ->toBe(['images/debian/versions/debian-v13']);
});

it('skips image versions the catalog marks unselectable', function () {
    expect(collect((new DataforestService('test-token'))->getRegionImageVersions(dataforestRegionDetail()))->pluck('ref')->all())
        ->not->toContain('images/debian/versions/debian-v11');
});

it('reuses an SSH key that is already on the key ring, matching on the key body', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/sshkeys' => Http::response([
            'ssh_keys' => [
                ['id' => 'key-1', 'name' => 'laptop', 'public_key' => 'ssh-ed25519 AAAAC3Nza-body laptop@elsewhere'],
            ],
        ]),
    ]);

    expect((new DataforestService('test-token'))->ensureSshKey('coolify', 'ssh-ed25519 AAAAC3Nza-body coolify@here'))
        ->toBe('key-1');

    Http::assertSentCount(1);
});

it('uploads an SSH key that is not on the key ring yet', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/sshkeys' => Http::sequence()
            ->push(['ssh_keys' => []])
            ->push(['id' => 'key-new', 'name' => 'coolify']),
    ]);

    expect((new DataforestService('test-token'))->ensureSshKey('coolify', 'ssh-ed25519 AAAAnew coolify@here'))
        ->toBe('key-new');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.dataforest.net/api/v1/public/sshkeys'
        && $request['public_key'] === 'ssh-ed25519 AAAAnew coolify@here');
});

it('sends an idempotency key with a seed order', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/seeds' => Http::response(['id' => 'seed-1', 'state' => 'processing'], 202),
    ]);

    (new DataforestService('test-token'))->createSeed(['name' => 'seed'], idempotencyKey: 'idem-1');

    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'idem-1'));
});

it('prefers the IPv4 address and falls back to the configurable IPv6 address', function () {
    $service = new DataforestService('test-token');

    expect($service->getPublicIpAddress([
        'ipv4' => [['address' => '203.0.113.10', 'primary_ip' => '203.0.113.10']],
        'ipv6' => [['address' => '2a01:db8::', 'primary_ip' => '2a01:db8::1']],
    ]))->toBe('203.0.113.10');

    // For IPv6 the seed carries the /64 network in `address`; only `primary_ip` is reachable.
    expect($service->getPublicIpAddress([
        'ipv4' => [],
        'ipv6' => [['address' => '2a01:db8::', 'primary_ip' => '2a01:db8::1']],
    ]))->toBe('2a01:db8::1');

    expect($service->getPublicIpAddress(['ipv4' => [], 'ipv6' => []]))->toBeNull();
});

it('finds a seed by the IP of the server', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/seeds?*' => Http::response([
            'seeds' => [
                ['id' => 'seed-a', 'ipv4' => [['address' => '203.0.113.9', 'primary_ip' => '203.0.113.9']]],
                ['id' => 'seed-b', 'ipv4' => [['address' => '203.0.113.10', 'primary_ip' => '203.0.113.10']]],
            ],
            'meta' => ['page' => 1, 'limit' => 100, 'total' => 2, 'total_pages' => 1],
        ]),
    ]);

    expect((new DataforestService('test-token'))->findSeedByIp('203.0.113.10')['id'])->toBe('seed-b');
});

it('raises a rate limit exception that carries the retry delay', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/team' => Http::response(
            ['code' => 'ERR_RATE_LIMITED', 'message' => 'Rate limit exceeded.'],
            429,
            ['Retry-After' => '42']
        ),
    ]);

    expect(fn () => (new DataforestService('test-token'))->getTeam())
        ->toThrow(RateLimitException::class);

    try {
        (new DataforestService('test-token'))->getTeam();
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(42);
    }
});

it('keeps the per-field details of a validation error in the message', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/seeds' => Http::response([
            'code' => 'ERR_VALIDATION',
            'message' => 'Validation failed',
            'details' => [['field' => 'name', 'message' => 'name is required']],
        ], 422),
    ]);

    expect(fn () => (new DataforestService('test-token'))->createSeed([]))
        ->toThrow(Exception::class, 'dataforest API error: Validation failed (name name is required)');
});
