<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One row per change of product_sizes.stock (bucket "sellable") or
 * product_sizes.quarantined_stock (bucket "quarantine"). Rows are never updated or deleted.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_INITIAL = 'initial';
    public const TYPE_SALE = 'sale';
    public const TYPE_CANCELLATION = 'cancellation';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_RETURN_QUARANTINE = 'return_quarantine';

    public const BUCKET_SELLABLE = 'sellable';
    public const BUCKET_QUARANTINE = 'quarantine';

    protected $fillable = [
        'product_id',
        'product_size_id',
        'type',
        'bucket',
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'reference_type',
        'reference_id',
        'user_id',
        'admin_id',
        'reason',
    ];

    protected static function booted()
    {
        // The ledger is history: no code path (or admin screen) may rewrite it.
        static::updating(fn () => throw new LogicException('Stock movements cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Stock movements cannot be deleted.'));
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
