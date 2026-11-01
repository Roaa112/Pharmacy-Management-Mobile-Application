<?php

namespace App\Modules\Inventory\Services;

use App\Models\ProductSize;
use DomainException;

/** A stock change would take a bucket below zero (or reserve more than is on hand). Nothing was changed. */
class InsufficientStock extends DomainException
{
    public function __construct(
        public readonly ?ProductSize $size,
        public readonly ?int $warehouseId,
        public readonly string $bucket,
        public readonly int $available,
        public readonly int $requested,
    ) {
        $label = $size ? ($size->product?->name_en ?? 'Product').' '.$size->size : 'SKU';
        $where = $warehouseId ? " in warehouse #{$warehouseId}" : '';

        parent::__construct("Not enough {$bucket} stock of {$label}{$where}: {$available} available, {$requested} needed.");
    }
}
