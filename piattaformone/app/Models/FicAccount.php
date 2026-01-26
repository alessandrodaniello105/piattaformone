<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FicAccount extends Model
{
    use HasFactory;
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fic_accounts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'company_id',
        'company_name',
        'company_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'token_refreshed_at',
        'status',
        'status_note',
        'webhook_url',
        'webhook_enabled',
        'webhook_verified_at',
        'metadata',
        'settings',
        'tenant_id',
        'connected_at',
        'last_sync_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'token_refreshed_at' => 'datetime',
            'webhook_enabled' => 'boolean',
            'webhook_verified_at' => 'datetime',
            'metadata' => 'array',
            'settings' => 'array',
            'connected_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    /**
     * Get the team that owns this FIC account.
     *
     * @return BelongsTo<\App\Models\Team, FicAccount>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Team::class, 'tenant_id');
    }

    /**
     * Get the subscriptions for the account.
     *
     * @return HasMany<FicSubscription>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(FicSubscription::class, 'fic_account_id');
    }

    /**
     * Get the clients for the account.
     *
     * @return HasMany<FicClient>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(FicClient::class, 'fic_account_id');
    }

    /**
     * Get the quotes for the account.
     *
     * @return HasMany<FicQuote>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(FicQuote::class, 'fic_account_id');
    }

    /**
     * Get the invoices for the account.
     *
     * @return HasMany<FicInvoice>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(FicInvoice::class, 'fic_account_id');
    }

    /**
     * Get the suppliers for the account.
     *
     * @return HasMany<FicSupplier>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(FicSupplier::class, 'fic_account_id');
    }

    /**
     * Get the events for the account.
     *
     * @return HasMany<FicEvent>
     */
    public function events(): HasMany
    {
        return $this->hasMany(FicEvent::class, 'fic_account_id');
    }

    /**
     * Check if the access token is expired.
     *
     * @return bool
     */
    public function isTokenExpired(): bool
    {
        if (!$this->token_expires_at) {
            return true;
        }

        return $this->token_expires_at->isPast();
    }

    /**
     * Check if the account needs re-authentication.
     *
     * Returns true if the token is expired, the account status indicates
     * a problem, or the access token is missing.
     *
     * @return bool
     */
    public function needsReauth(): bool
    {
        return $this->isTokenExpired()
            || in_array($this->status, ['needs_refresh', 'revoked', 'suspended'])
            || empty($this->access_token);
    }

    /**
     * Scope to get only active accounts with valid tokens.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('access_token')
            ->where(function ($q) {
                $q->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '>', now());
            });
    }

    /**
     * Scope to get accounts that need reconnection.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDisconnected($query)
    {
        return $query->whereIn('status', ['disconnected', 'revoked', 'suspended']);
    }

    /**
     * Scope to filter accounts by team/tenant ID.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int|string|null  $teamId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTeam($query, $teamId)
    {
        if ($teamId === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where('tenant_id', $teamId);
    }
}
