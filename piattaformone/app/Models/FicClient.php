<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FicClient extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fic_clients';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fic_account_id',
        'fic_client_id',
        'name',
        'code',
        'fic_created_at',
        'fic_updated_at',
        'raw',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fic_created_at' => 'datetime',
            'fic_updated_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    /**
     * Get the FIC account that owns the client.
     *
     * @return BelongsTo<FicAccount, FicClient>
     */
    public function ficAccount(): BelongsTo
    {
        return $this->belongsTo(FicAccount::class, 'fic_account_id');
    }

    /**
     * Get the date to use for analytics.
     *
     * @return \Illuminate\Support\Carbon|null
     */
    public function analyticsDate()
    {
        return $this->fic_created_at;
    }
}
