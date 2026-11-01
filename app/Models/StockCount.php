<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A physical count of a warehouse. Completing it records the difference between counted units
 * and on_hand at that moment as count corrections.
 */
class StockCount extends Model
{
    public const DRAFT = 'draft';
    public const COMPLETED = 'completed';
    public const CANCELED = 'canceled';

    protected $fillable = ['reference', 'warehouse_id', 'status', 'notes', 'created_by', 'completed_by', 'completed_at'];

    protected $casts = ['completed_at' => 'datetime'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(StockCountItem::class);
    }
}
