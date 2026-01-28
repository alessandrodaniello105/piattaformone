<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FicEvent extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fic_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fic_account_id',
        'event_type',
        'resource_type',
        'fic_resource_id',
        'occurred_at',
        'payload',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * Get the FIC account that owns the event.
     *
     * @return BelongsTo<FicAccount, FicEvent>
     */
    public function ficAccount(): BelongsTo
    {
        return $this->belongsTo(FicAccount::class, 'fic_account_id');
    }
}
