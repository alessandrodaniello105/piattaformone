<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FicInvoice extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fic_invoices';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fic_account_id',
        'fic_invoice_id',
        'number',
        'status',
        'total_gross',
        'fic_date',
        'fic_created_at',
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
            'total_gross' => 'decimal:2',
            'fic_date' => 'date',
            'fic_created_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    /**
     * Get the FIC account that owns the invoice.
     *
     * @return BelongsTo<FicAccount, FicInvoice>
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
        return $this->fic_created_at ?? $this->fic_date;
    }
}
