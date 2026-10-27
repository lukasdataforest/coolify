<?php

use App\Actions\Server\DeleteServer;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    $this->team = Team::factory()->create();
    $this->token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'dataforest',
        'token' => 'test-dataforest-token',
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'cloud_provider_token_id' => $this->token->id,
        'dataforest_seed_id' => 'seed-1',
    ]);
    $this->server->delete();
});

it('deletes the dataforest seed when requested', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/seeds/seed-1' => Http::response([
            'id' => 'action-1',
            'type' => 'seed.destroy',
            'status' => 'new',
        ], 202),
    ]);

    DeleteServer::run(
        serverId: $this->server->id,
        cloudProviderTokenId: $this->token->id,
        teamId: $this->team->id,
        deleteFromDataforest: true,
        dataforestSeedId: 'seed-1',
    );

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.dataforest.net/api/v1/public/seeds/seed-1');
    expect(Server::withTrashed()->find($this->server->id))->toBeNull();
});

it('retains the server when the dataforest seed cannot be deleted', function () {
    Http::fake([
        'https://api.dataforest.net/api/v1/public/seeds/seed-1' => Http::response([
            'code' => 'ERR_SEED_BUSY',
            'message' => 'Seed is still being created and cannot be deleted yet',
        ], 409),
    ]);

    expect(fn () => DeleteServer::run(
        serverId: $this->server->id,
        cloudProviderTokenId: $this->token->id,
        teamId: $this->team->id,
        deleteFromDataforest: true,
        dataforestSeedId: 'seed-1',
    ))->toThrow(Exception::class, 'Seed is still being created and cannot be deleted yet');

    expect(Server::withTrashed()->find($this->server->id))->not->toBeNull();
});

it('does not call dataforest when the seed should be kept', function () {
    DeleteServer::run(
        serverId: $this->server->id,
        cloudProviderTokenId: $this->token->id,
        teamId: $this->team->id,
        dataforestSeedId: 'seed-1',
    );

    Http::assertNothingSent();
    expect(Server::withTrashed()->find($this->server->id))->toBeNull();
});

it('never touches dataforest for a server that was transferred away', function () {
    $this->server->restore();
    $this->server->update(['server_metadata' => ['transfer' => ['status' => 'transferred']]]);
    $this->server->delete();

    DeleteServer::run(
        serverId: $this->server->id,
        cloudProviderTokenId: $this->token->id,
        teamId: $this->team->id,
        deleteFromDataforest: true,
        dataforestSeedId: 'seed-1',
    );

    Http::assertNothingSent();
});
