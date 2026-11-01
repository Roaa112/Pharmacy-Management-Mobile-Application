<?php

namespace App\Modules\Reports;

use App\Models\ReturnRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Revenue rules shared by the dashboard home, the orders list and the reports.
 *
 * Revenue = completed orders' totals − refunds approved on them. A partial refund lowers revenue
 * by the refunded amount instead of removing the whole order (decision D3, now applied).
 * Accepted returns from before approved_amount existed count with the amount requested.
 */
final class Revenue
{
    /** Order total minus the refunds approved on it, summed. */
    public const NET_SUM = 'SUM(orders.total_price - (select coalesce(sum(coalesce(rr.approved_amount, rr.refund_amount, 0)), 0) from return_requests rr where rr.order_id = orders.id and rr.status = \'accepted\'))';

    public static function completed(Builder $orders): Builder
    {
        return $orders->where('orders.status', 'completed');
    }

    /** Net revenue of the completed orders in $orders. */
    public static function net(Builder $orders): float
    {
        $completed = self::completed(clone $orders);
        $gross = (float) (clone $completed)->sum('orders.total_price');
        $refunds = (float) ReturnRequest::where('status', 'accepted')
            ->whereIn('order_id', (clone $completed)->select('orders.id'))
            ->sum(DB::raw('coalesce(approved_amount, refund_amount, 0)'));

        return round($gross - $refunds, 2);
    }

    /** Approved refunds per order, as a subquery to subtract in grouped queries. */
    public static function refundsSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('return_requests')
            ->selectRaw('coalesce(sum(coalesce(approved_amount, refund_amount, 0)), 0)')
            ->whereColumn('return_requests.order_id', 'orders.id')
            ->where('return_requests.status', 'accepted');
    }

    /**
     * SQL for the store's local calendar date of a UTC timestamp column.
     *
     * Egyptian time changes offset with summer time (UTC+2 / UTC+3), so each timestamp is shifted
     * by the offset in force at that moment: a CASE over the zone's transitions (from the tz
     * database, portable across MySQL, SQLite and PostgreSQL, no timezone tables needed).
     */
    public static function localDate(string $column): string
    {
        $zone = new \DateTimeZone(config('pharmacy.timezone'));
        $transitions = $zone->getTransitions(strtotime('2015-01-01'), strtotime('+5 years'));

        // Offset before the first transition in the window.
        $cases = [];
        foreach (array_slice($transitions, 1) as $i => $transition) {
            $before = intdiv($transitions[$i]['offset'], 60);
            $at = gmdate('Y-m-d H:i:s', $transition['ts']);
            $cases[] = "WHEN {$column} < '{$at}' THEN ".self::shiftedDate($column, $before);
        }
        $last = self::shiftedDate($column, intdiv(end($transitions)['offset'], 60));

        return $cases ? 'CASE '.implode(' ', $cases)." ELSE {$last} END" : $last;
    }

    private static function shiftedDate(string $column, int $minutes): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date({$column}, '".($minutes >= 0 ? '+' : '')."{$minutes} minutes')",
            'pgsql' => "date({$column} + interval '{$minutes} minutes')",
            default => "date(date_add({$column}, interval {$minutes} minute))",
        };
    }
}
