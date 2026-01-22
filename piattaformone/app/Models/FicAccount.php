<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
}
