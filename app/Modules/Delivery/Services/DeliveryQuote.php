<?php

namespace App\Modules\Delivery\Services;

use App\Models\DeliveryRate;
use App\Models\DeliveryZone;

/** The result of pricing delivery to one address for one order subtotal. */
final class DeliveryQuote
{
    public const NO_ZONE = 'city_not_covered';
    public const BELOW_MINIMUM = 'below_minimum';

    public function __construct(
        public readonly ?DeliveryZone $zone,
        public readonly ?DeliveryRate $rate,
        public readonly int $feeMinor,
        public readonly bool $freeDelivery = false,
        public readonly ?string $error = null,
        public readonly int $minimumMinor = 0,
    ) {
    }

    public static function failed(string $error, ?DeliveryZone $zone = null, ?DeliveryRate $rate = null, int $minimumMinor = 0): self
    {
        return new self($zone, $rate, 0, false, $error, $minimumMinor);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
