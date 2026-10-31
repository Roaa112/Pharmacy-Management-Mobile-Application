<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductSize extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'size',
        'price',
        'stock',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * The only way stock should change: locks the size row, applies the change and
     * writes the ledger row in the same transaction. Stock can never go below zero.
     *
     * $context keys: reference (Model), user_id, admin_id, reason.
     */
    public static function moveStock(int $sizeId, int $change, string $type, array $context = []): ?StockMovement
    {
        return DB::transaction(function () use ($sizeId, $change, $type, $context) {
            $size = static::lockForUpdate()->findOrFail($sizeId);

            return $size->recordMovement($change, $type, $context);
        });
    }

    /**
     * Set stock to an absolute value (admin count / initial stock), recorded as a movement.
     */
    public static function setStock(int $sizeId, int $newStock, string $type, array $context = []): ?StockMovement
    {
        return DB::transaction(function () use ($sizeId, $newStock, $type, $context) {
            $size = static::lockForUpdate()->findOrFail($sizeId);

            return $size->recordMovement($newStock - $size->stock, $type, $context);
        });
    }

    /**
     * Move units into or out of quarantine (returned medicine, decision D1). Quarantined units
     * are kept in quarantined_stock and never touch the sellable stock column.
     */
    public static function moveQuarantine(int $sizeId, int $change, string $type, array $context = []): ?StockMovement
    {
        return DB::transaction(function () use ($sizeId, $change, $type, $context) {
            $size = static::lockForUpdate()->findOrFail($sizeId);

            return $size->recordMovement($change, $type, $context, StockMovement::BUCKET_QUARANTINE);
        });
    }

    private function recordMovement(int $change, string $type, array $context, string $bucket = StockMovement::BUCKET_SELLABLE): ?StockMovement
    {
        if ($change === 0) {
            return null;
        }

        $column = $bucket === StockMovement::BUCKET_QUARANTINE ? 'quarantined_stock' : 'stock';
        $before = (int) $this->{$column};
        $after = $before + $change;

        if ($after < 0) {
            throw new InvalidArgumentException("{$column} of size {$this->id} cannot go below zero ({$before} {$change}).");
        }

        static::whereKey($this->id)->update([$column => $after]);
        $this->{$column} = $after;

        $reference = $context['reference'] ?? null;

        return StockMovement::create([
            'product_id' => $this->product_id,
            'product_size_id' => $this->id,
            'type' => $type,
            'bucket' => $bucket,
            'quantity_before' => $before,
            'quantity_change' => $change,
            'quantity_after' => $after,
            'reference_type' => $reference ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->getKey(),
            'user_id' => $context['user_id'] ?? null,
            'admin_id' => $context['admin_id'] ?? null,
            'reason' => $context['reason'] ?? null,
        ]);
    }
}
