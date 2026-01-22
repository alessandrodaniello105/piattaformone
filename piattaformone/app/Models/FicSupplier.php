<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FicSupplier extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fic_suppliers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fic_account_id',
        'fic_supplier_id',
        'name',
        'code',
        'vat_number',
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
     * Get the FIC account that owns the supplier.
     *
     * @return BelongsTo<FicAccount, FicSupplier>
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

    /**
     * Get a field from raw data if not available as a normalized column.
     *
     * This allows easy access to any field from FIC API even if it's not normalized.
     * Example: $supplier->getRawField('email') or $supplier->getRawField('tax_code')
     *
     * @param  string  $field  The field name to retrieve from raw data
     * @param  mixed  $default  Default value if field doesn't exist
     * @return mixed
     */
    public function getRawField(string $field, mixed $default = null): mixed
    {
        return $this->raw[$field] ?? $default;
    }
}
