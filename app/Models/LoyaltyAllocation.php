<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Which lot a points movement took from (+) or gave back to (−). Never changed or deleted. */
class LoyaltyAllocation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'loyalty_ledger_allocations';

    protected $fillable = ['entry_id', 'lot_id', 'points'];

    protected $casts = ['points' => 'integer'];

    protected static function booted()
    {
        static::updating(fn () => throw new LogicException('Loyalty allocations cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Loyalty allocations cannot be deleted.'));
    }

    public function entry()
    {
        return $this->belongsTo(LoyaltyLedgerEntry::class, 'entry_id');
    }

    public function lot()
    {
        return $this->belongsTo(LoyaltyLedgerEntry::class, 'lot_id');
    }
}
