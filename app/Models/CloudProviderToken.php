<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class CloudProviderToken extends BaseModel
{
    use HasFactory;

    /**
     * Cloud providers Coolify can hold an API token for.
     */
    public const PROVIDERS = ['hetzner', 'digitalocean', 'vultr', 'hostinger', 'dataforest'];

    protected $fillable = [
        'team_id',
        'provider',
        'token',
        'name',
        'description',
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'token' => 'encrypted',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function hasServers(): bool
    {
        return $this->servers()->exists();
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }

    /**
     * The provider name as the provider writes it; most are just capitalized.
     */
    public static function providerLabel(?string $provider): string
    {
        return match ($provider) {
            null, '' => '',
            'digitalocean' => 'DigitalOcean',
            'dataforest' => 'dataforest',
            default => ucfirst($provider),
        };
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
