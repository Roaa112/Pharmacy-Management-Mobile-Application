<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Models\PromotionReward;

/** What an order-level promotion (gift, spend, free delivery) would give for this cart. */
final class OrderOutcome
{
    /** The cart has items this promotion is about (so a rejection is worth telling the customer). */
    public bool $relevant = false;

    /** @var array{0: string, 1: array}|null */
    public ?array $rejection = null;

    /** Something the customer can still unlock, e.g. "spend 300 more". */
    public ?array $hint = null;

    /** Qualifying amount (minor units) or quantity, for the snapshot. */
    public int $eligible = 0;

    /** @var list<array{reward: PromotionReward, quantity: int}> */
    public array $gifts = [];

    /** @var list<array{reward: ?PromotionReward, amount: int}> order discounts in minor units */
    public array $discounts = [];

    /** @var list<array{reward: ?PromotionReward, cap: ?int}> free delivery offers (cap in minor units) */
    public array $delivery = [];

    public array $details = [];

    public static function irrelevant(): self
    {
        return new self();
    }

    public static function rejected(string $reason, array $params = [], array $details = []): self
    {
        $outcome = new self();
        $outcome->relevant = true;
        $outcome->rejection = [$reason, $params];
        $outcome->details = $details;

        return $outcome;
    }

    public function applies(): bool
    {
        return $this->rejection === null && ($this->gifts || $this->discounts || $this->delivery);
    }
}
