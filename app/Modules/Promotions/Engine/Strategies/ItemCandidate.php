<?php

namespace App\Modules\Promotions\Engine\Strategies;

use Closure;

/**
 * One item-level promotion that could set a line's unit price. The ConflictResolver orders
 * candidates by: flash sale first (rank), explicit priority, target specificity, then id.
 */
final class ItemCandidate
{
    /**
     * @param  Closure(int): int  $apply  unit price in minor units → discounted unit price
     */
    public function __construct(
        public string $source,
        public int $id,
        public string $type,
        public string $name,
        public int $rank,
        public int $priority,
        public int $specificity,
        public bool $stackable,
        public Closure $apply,
        public mixed $model = null,
    ) {
    }

    public function sortKey(): array
    {
        return [-$this->rank, -$this->priority, -$this->specificity, $this->id];
    }
}
