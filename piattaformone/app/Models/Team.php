<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    /** @use HasFactory<\Database\Factories\TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
        'fic_client_id',
        'fic_client_secret',
        'fic_redirect_uri',
        'fic_company_id',
        'fic_scopes',
        'fic_configured_at',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'fic_client_secret' => 'encrypted',
            'fic_scopes' => 'array',
            'fic_configured_at' => 'datetime',
        ];
    }

    /**
     * Check if team has FIC OAuth credentials configured.
     */
    public function hasFicCredentials(): bool
    {
        return !empty($this->fic_client_id) && !empty($this->fic_client_secret);
    }

    /**
     * Get FIC OAuth scopes for this team.
     *
     * @return array<string>
     */
    public function getFicScopes(): array
    {
        return $this->fic_scopes ?? config('fattureincloud.scopes', []);
    }

    /**
     * Get FIC accounts for this team.
     *
     * @return HasMany<FicAccount>
     */
    public function ficAccounts(): HasMany
    {
        return $this->hasMany(FicAccount::class, 'tenant_id');
    }
}

