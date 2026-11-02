<?php

namespace App\Modules\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Revenue rules shared by the dashboard home, the orders list and the reports.
 *
 * Revenue = completed orders' totals − money actually refunded on them (succeeded refunds). An
 * approved return whose refund was later canceled, or is not paid yet, does not lower revenue;
 * refunds of a duplicate capture give back money the order never kept and are not counted.
 * Returns accepted before refunds existed were migrated as succeeded refunds, so history is unchanged.
 */
final class Revenue
{
    /** Succeeded refunds of one order (refunds alias rf), excluding duplicate-capture refunds. */
    private const REFUNDED = '(select coalesce(sum(rf.amount), 0) from refunds rf where rf.order_id = orders.id and rf.status = \'succeeded\' and (rf.payment_id is null or rf.payment_id not in (select dp.id from payments dp where dp.order_id = orders.id and dp.duplicate_of_payment_id is not null)))';

    /** Order total minus the money refunded on it, summed. */
    public const NET_SUM = 'SUM(orders.total_price - '.self::REFUNDED.')';

    public static function completed(Builder $orders): Builder
    {
        return $orders->where('orders.status', 'completed');
    }

    /** Net revenue of the completed orders in $orders. */
    public static function net(Builder $orders): float
    {
        return round((float) self::completed(clone $orders)->sum(DB::raw('orders.total_price - '.self::REFUNDED)), 2);
    }

    /** Money refunded per order, as a subquery to subtract in grouped queries. */
    public static function refundsSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('refunds')
            ->selectRaw('coalesce(sum(refunds.amount), 0)')
            ->whereColumn('refunds.order_id', 'orders.id')
            ->where('refunds.status', 'succeeded')
            ->where(fn ($q) => $q->whereNull('refunds.payment_id')->orWhereNotIn('refunds.payment_id', DB::table('payments')->whereNotNull('duplicate_of_payment_id')->select('id')));
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
