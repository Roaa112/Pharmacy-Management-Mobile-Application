<?php

namespace App\Modules\Promotions\Engine;

use App\Modules\Promotions\Engine\Strategies\ItemCandidate;

/**
 * The single place that decides which promotions win when several could apply. Never relies
 * on database order: every decision is an explicit, total ordering.
 *
 * Item prices (per line):
 *   1. a live flash sale beats any discount rule (decision D7);
 *   2. then higher priority;
 *   3. then the more specific target (variant > product > brand > category);
 *   4. then the older promotion (lower id).
 *   The winner applies. Others apply on top only when the winner AND they are stackable
 *   (and not exclusive); they then apply in the same order, each on the running price. Flash
 *   sales never stack. Everything else is rejected with a reason.
 *
 * Order discounts (Spend X fixed/percent rewards and the coupon):
 *   automatic ones in precedence order: the first applies; later ones only when both are
 *   stackable. A coupon that does not combine with promotions competes with them: whichever
 *   gives the larger discount applies (the coupon on a tie); the other is rejected.
 *
 * Delivery: one free-delivery offer applies, the one waiving most (precedence on a tie).
 */
class ConflictResolver
{
    /**
     * @param  list<ItemCandidate>  $candidates
     * @return array{0: list<ItemCandidate>, 1: list<array{0: ItemCandidate, 1: string}>} [applied in order, rejected with reason]
     */
    public function resolveItem(array $candidates): array
    {
        usort($candidates, fn (ItemCandidate $a, ItemCandidate $b) => $a->sortKey() <=> $b->sortKey());

        $applied = [];
        $rejected = [];

        foreach ($candidates as $candidate) {
            if (!$applied) {
                $applied[] = $candidate;
                continue;
            }

            if ($applied[0]->stackable && $candidate->stackable && $candidate->source === 'promotion') {
                // The same promotion never applies twice to one line.
                if (!collect($applied)->contains(fn ($a) => $a->source === $candidate->source && $a->id === $candidate->id)) {
                    $applied[] = $candidate;
                }
                continue;
            }

            $rejected[] = [$candidate, $applied[0]->stackable && !$candidate->stackable ? 'not_stackable' : 'conflict_lower_precedence'];
        }

        return [$applied, $rejected];
    }

    /**
     * @param  list<array{key: string, amount: int, stackable: bool}>  $automatic  in precedence order
     * @return array{0: list<string>, 1: list<string>} [kept keys, rejected keys]
     */
    public function resolveAutomaticDiscounts(array $automatic): array
    {
        $kept = [];
        $rejected = [];

        foreach ($automatic as $offer) {
            if ($offer['amount'] <= 0) {
                continue;
            }
            if (!$kept || ($kept[0]['stackable'] && $offer['stackable'])) {
                $kept[] = $offer;
            } else {
                $rejected[] = $offer['key'];
            }
        }

        return [array_column($kept, 'key'), $rejected];
    }

    /** true = the coupon applies and the automatic discounts are dropped. */
    public function couponBeatsAutomatic(int $couponMinor, int $automaticMinor): bool
    {
        return $couponMinor >= $automaticMinor;
    }

    /**
     * @param  list<array{key: string, amount: int}>  $offers  in precedence order, amount = fee it would waive
     */
    public function resolveDelivery(array $offers): ?string
    {
        $best = null;
        foreach ($offers as $offer) {
            if ($best === null || $offer['amount'] > $best['amount']) {
                $best = $offer;
            }
        }

        return $best['key'] ?? null;
    }
}
