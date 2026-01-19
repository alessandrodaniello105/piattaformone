<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FicSubscription extends Model
{
    use HasFactory;
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fic_subscriptions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fic_account_id',
        'fic_subscription_id',
        'event_group',
        'webhook_secret',
        'expires_at',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the FIC account that owns the subscription.
     *
     * @return BelongsTo<FicAccount, FicSubscription>
     */
    public function ficAccount(): BelongsTo
    {
        return $this->belongsTo(FicAccount::class, 'fic_account_id');
    }

    /**
     * Scope a query to only include active subscriptions.
     *
     * @param Builder<FicSubscription> $query
     * @return Builder<FicSubscription>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include subscriptions expiring within the specified days.
     *
     * @param Builder<FicSubscription> $query
     * @param int $days Number of days ahead to check (default: 15)
     * @return Builder<FicSubscription>
     */
    public function scopeExpiring(Builder $query, int $days = 15): Builder
    {
        $cutoffDate = now()->addDays($days);
        return $query->where('expires_at', '<=', $cutoffDate)
            ->whereNotNull('expires_at');
    }

    /**
     * Scope a query to filter by event group.
     *
     * @param Builder<FicSubscription> $query
     * @param string $group The event group name
     * @return Builder<FicSubscription>
     */
    public function scopeByEventGroup(Builder $query, string $group): Builder
    {
        return $query->where('event_group', $group);
    }
}
