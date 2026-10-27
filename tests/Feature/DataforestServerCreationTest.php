<?php

use App\Livewire\Server\Create;
use App\Livewire\Server\CreatePage;
use App\Livewire\Server\New\ByDataforest;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
    ]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'dataforest',
        'token' => 'dataforest-token',
        'name' => 'Production dataforest',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

/**
 * The catalog calls the step 2 form makes before anything can be selected.
 */
function fakeDataforestCatalog(array $extra = []): void
{
    Http::fake(array_merge([
        'https://api.dataforest.net/api/v1/public/team' => Http::response([
            'id' => 'team-1',
            'name' => 'Acme',
            'projects' => [['id' => 'project-1', 'name' => 'Default']],
        ]),
        'https://api.dataforest.net/api/v1/public/regions?*' => Http::response([
            'data' => [['id' => '1', 'name' => 'Frankfurt 1', 'slug' => 'fra01', 'city' => 'Frankfurt', 'country' => 'Germany', 'is_active' => true]],
            'meta' => ['page' => 1, 'limit' => 100, 'total' => 1, 'total_pages' => 1],
        ]),
        'https://api.dataforest.net/api/v1/public/regions/fra01?*' => Http::response([
            'data' => [
                'slug' => 'fra01',
                'name' => 'Frankfurt 1',
                'ipv4' => ['capacity_status' => 'available'],
                'lines' => [[
                    'slug' => 'entry',
                    'name' => 'Entry',
                    'class' => 'shared',
                    'models' => [[
                        'slug' => 'entry-c1-m2-s20',
                        'name' => 'Entry C1',
                        'cpu' => 1,
                        'memory' => 2,
                        'storage' => 20,
                        'is_selectable' => true,
                        'capacity' => ['capacity_status' => 'available'],
                        'gross_max_price_per_month' => '5.90',
                    ]],
                ]],
                'images' => [[
                    'slug' => 'debian',
                    'name' => 'Debian',
                    'hardware' => 'generic',
                    'is_selectable' => true,
                    // The live catalog returns the bare version slug, not the full reference.
                    'versions' => [[
                        'slug' => 'debian-v13',
                        'display_name' => 'Debian 13',
                        'version' => '13',
                        'is_selectable' => true,
                    ]],
                ]],
            ],
        ]),
        // One URL serves both the key ring listing and the key upload.
        'https://api.dataforest.net/api/v1/public/sshkeys' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['id' => 'key-coolify', 'name' => 'coolify'])
            : Http::response(['ssh_keys' => []]),
    ], $extra));
}

/**
 * Fill the step 2 form with the fixture catalog above.
 */
function configuredDataforestForm(string $tokenUuid, string $name, int $privateKeyId): Testable
{
    return Livewire::test(ByDataforest::class, ['selectedTokenUuid' => $tokenUuid])
        ->call('loadDataforestData')
        ->set('server_name', $name)
        ->set('selected_project_id', 'project-1')
        ->set('selected_region', 'fra01')
        ->set('selected_plan', 'lines/entry/models/entry-c1-m2-s20')
        ->set('selected_image_ref', 'images/debian/versions/debian-v13')
        ->set('private_key_id', $privateKeyId);
}

it('offers dataforest as a server provider', function () {
    Livewire::test(Create::class)
        ->assertSee('dataforest')
        ->assertSee(route('server.create.type', ['type' => 'dataforest']), false);

    Livewire::test(CreatePage::class, ['type' => 'dataforest'])
        ->assertSet('title', 'dataforest')
        ->assertSet('tokenProvider', 'dataforest');
});

it('uses the shared cloud provider UI', function () {
    Livewire::test(ByDataforest::class)
        ->assertSee('dataforest account')
        ->assertSee('https://cloud.dataforest.net/en', false);

    Livewire::test(ByDataforest::class, ['selectedTokenUuid' => $this->token->uuid])
        ->set('loading_data', false)
        ->assertSee('dataforest seed')
        ->assertSee('Advanced options')
        ->assertSee('Buy and create');
});

it('asks for a public IPv4 by default and leaves paid backups off', function () {
    Livewire::test(ByDataforest::class, ['selectedTokenUuid' => $this->token->uuid])
        ->assertSet('enable_ipv4', true)
        ->assertSet('enable_backups', false)
        ->set('loading_data', false)
        ->assertSee('Add a public IPv4 address')
        ->assertSee('Enable daily dataforest backups');
});

it('selects the only project automatically and loads the plans of the chosen region', function () {
    fakeDataforestCatalog();

    Livewire::test(ByDataforest::class, ['selectedTokenUuid' => $this->token->uuid])
        ->call('loadDataforestData')
        ->assertSet('selected_project_id', 'project-1')
        ->assertSet('plans', [])
        ->set('selected_region', 'fra01')
        ->assertSet('plans.0.plan', 'lines/entry/models/entry-c1-m2-s20')
        ->assertSet('images.0.ref', 'images/debian/versions/debian-v13')
        ->assertSee('Entry C1');
});

it('creates a dataforest seed and the linked Coolify server', function () {
    fakeDataforestCatalog([
        'https://api.dataforest.net/api/v1/public/seeds' => Http::response([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'coolify-seed',
            'state' => 'processing',
            'ipv4' => [['address' => '203.0.113.10', 'primary_ip' => '203.0.113.10']],
        ], 202),
    ]);

    configuredDataforestForm($this->token->uuid, 'coolify-seed', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    $server = Server::query()->where('dataforest_seed_id', '550e8400-e29b-41d4-a716-446655440000')->firstOrFail();

    expect($server->name)->toBe('coolify-seed')
        ->and($server->ip)->toBe('203.0.113.10')
        ->and($server->dataforest_seed_status)->toBe('processing')
        ->and($server->cloud_provider_token_id)->toBe($this->token->id);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.dataforest.net/api/v1/public/seeds'
        && $request['project_id'] === 'project-1'
        && $request['location'] === 'fra01'
        && $request['plan'] === 'lines/entry/models/entry-c1-m2-s20'
        && $request['source'] === ['type' => 'image', 'ref' => 'images/debian/versions/debian-v13']
        && $request['enable_ipv4'] === true
        && $request['backups'] === false
        && $request->hasHeader('Idempotency-Key'));
});

it('adds the Coolify SSH key to the seed, uploading it to the key ring first', function () {
    fakeDataforestCatalog([
        'https://api.dataforest.net/api/v1/public/sshkeys' => Http::sequence()
            ->push(['ssh_keys' => []])   // form load
            ->push(['ssh_keys' => []])   // ensureSshKey lookup
            ->push(['id' => 'key-new', 'name' => 'coolify']),
        'https://api.dataforest.net/api/v1/public/seeds' => Http::response([
            'id' => 'seed-1',
            'state' => 'processing',
            'ipv4' => [['address' => '203.0.113.11', 'primary_ip' => '203.0.113.11']],
        ], 202),
    ]);

    configuredDataforestForm($this->token->uuid, 'seed-with-key', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.dataforest.net/api/v1/public/sshkeys'
        && $request['public_key'] === $this->privateKey->getPublicKey());

    Http::assertSent(fn ($request) => $request->url() === 'https://api.dataforest.net/api/v1/public/seeds'
        && $request['ssh_keys'] === ['key-new']);
});

it('keeps the created seed linked while its public IP is pending', function () {
    fakeDataforestCatalog([
        'https://api.dataforest.net/api/v1/public/seeds' => Http::response([
            'id' => 'seed-pending',
            'state' => 'processing',
            'ipv4' => [],
            'ipv6' => [],
        ], 202),
        'https://api.dataforest.net/api/v1/public/seeds/seed-pending' => Http::response(['message' => 'Still provisioning'], 503),
    ]);

    configuredDataforestForm($this->token->uuid, 'pending-seed', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Server::query()->where('dataforest_seed_id', 'seed-pending')->firstOrFail()->ip)
        ->toBe(Server::PLACEHOLDER_IP);
});

it('rejects a seed name that dataforest would not accept', function () {
    fakeDataforestCatalog();

    configuredDataforestForm($this->token->uuid, str_repeat('a', 51), $this->privateKey->id)
        ->call('submit')
        ->assertHasErrors('server_name');

    expect(Server::query()->whereNotNull('dataforest_seed_id')->exists())->toBeFalse();
});

it('refuses a stale plan that the region no longer offers', function () {
    fakeDataforestCatalog();

    configuredDataforestForm($this->token->uuid, 'stale-plan', $this->privateKey->id)
        ->set('selected_plan', 'lines/entry/models/gone')
        ->call('submit')
        ->assertHasErrors('selected_plan');

    expect(Server::query()->whereNotNull('dataforest_seed_id')->exists())->toBeFalse();
});

it('surfaces a provider error instead of a half-filled form', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/team' => Http::response(
            ['code' => 'ERR_UNAUTHORIZED', 'message' => 'Missing or invalid authentication'],
            401
        ),
    ]);

    Livewire::test(ByDataforest::class, ['selectedTokenUuid' => $this->token->uuid])
        ->call('loadDataforestData')
        ->assertSet('provider_data_error', fn (string $error) => str_contains($error, 'Missing or invalid authentication'))
        ->assertSee('Unable to load dataforest');
});
