<?php

namespace App\Modules\Promotions\Engine;

use App\Models\Product;
use App\Models\ProductSize;

/** A customer-selected line after item-level promotions. Amounts in minor units. */
final class PricedLine
{
    /** @var list<array{source: string, id: int, type: string, name: string, unit_discount: int}> */
    public array $applied = [];

    public ?int $flashSaleId = null;
    public ?int $promotionId = null;

    public function __construct(
        public string|int $key,
        public Product $product,
        public ProductSize $size,
        public int $quantity,
        public int $originalUnit,
        public int $unit,
    ) {
    }

    public function originalTotal(): int
    {
        return $this->originalUnit * $this->quantity;
    }

    public function total(): int
    {
        return $this->unit * $this->quantity;
    }

    public function discount(): int
    {
        return $this->originalTotal() - $this->total();
    }

    public function isDiscounted(): bool
    {
        return $this->applied !== [];
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'product_id' => $this->product->id,
            'product_size_id' => $this->size->id,
            'quantity' => $this->quantity,
            'original_unit_price' => Money::out($this->originalUnit),
            'unit_price' => Money::out($this->unit),
            'original_total' => Money::out($this->originalTotal()),
            'total' => Money::out($this->total()),
            'discount' => Money::out($this->discount()),
            'flash_sale_id' => $this->flashSaleId,
            'promotion_id' => $this->promotionId,
            'promotions' => array_map(fn ($a) => ['source' => $a['source'], 'id' => $a['id'], 'type' => $a['type'], 'name' => $a['name'],
                'unit_discount' => Money::out($a['unit_discount'])], $this->applied),
        ];
    }
}
