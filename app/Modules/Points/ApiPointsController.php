<?php

namespace App\Modules\Points;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyLedgerEntry as Entry;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Modules\Points\Requests\PointsHistoryRequest;
use App\Modules\Points\Resources\LoyaltyEntryResource;
use App\Modules\Points\Resources\RedemptionResource;
use App\Modules\Points\Resources\RewardResource;
use App\Modules\Points\Services\LoyaltyService;
use App\Modules\Points\Services\RewardRedemptionService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Customer loyalty endpoints. Everything is the signed-in customer's own (no ids of other
 * customers are accepted); numbers come from the ledger, the same as the dashboard.
 *
 * The three original endpoints (points/valid, points/expired, points/notvalid) keep their shape:
 * one row per order that earned points.
 */
class ApiPointsController extends Controller
{
    public function __construct(private LoyaltyService $loyalty, private RewardRedemptionService $redemptions)
    {
    }

    // ------------------------------------------------------------ original endpoints

    public function getValidPoints(Request $request)
    {
        $userId = $request->user()->id;
        $lots = Entry::usable()->where('user_id', $userId)->whereNotNull('order_id')->with('order:id,created_at')->orderBy('expires_at')->get();
        $breakdown = $this->loyalty->lotBreakdown($lots->pluck('id')->all());

        return response()->json([
            'valid_points_total' => $this->loyalty->ledger()->balance($userId),
            'orders' => $lots->map(fn (Entry $lot) => $this->orderRow($lot, $breakdown[$lot->id], (int) $lot->remaining_points) + [
                'expires_at' => $lot->expires_at?->toDateTimeString(),
            ])->values(),
        ]);
    }

    public function getExpiredPoints(Request $request)
    {
        $userId = $request->user()->id;
        // Written off by the expiry run, or past their expiry and not yet written off.
        $lots = Entry::lots()->where('user_id', $userId)->whereNotNull('order_id')
            ->where(fn ($q) => $q->where('status', Entry::STATUS_EXPIRED)->orWhere(fn ($a) => $a->where('status', Entry::STATUS_ACTIVE)
                ->where('remaining_points', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())))
            ->with('order:id,created_at')->orderByDesc('expires_at')->get();
        $breakdown = $this->loyalty->lotBreakdown($lots->pluck('id')->all());
        $amount = fn (Entry $lot) => $lot->status === Entry::STATUS_EXPIRED ? $breakdown[$lot->id]['expired'] : (int) $lot->remaining_points;

        return response()->json([
            'expired_points_total' => $lots->sum($amount),
            'orders' => $lots->map(fn (Entry $lot) => $this->orderRow($lot, $breakdown[$lot->id], $amount($lot)) + [
                'expired_at' => $lot->expires_at?->toDateTimeString(),
            ])->values(),
        ]);
    }

    public function getConsumedPoints(Request $request)
    {
        $userId = $request->user()->id;
        $lots = Entry::lots()->where('user_id', $userId)->whereNotNull('order_id')->with('order:id,created_at')->latest('id')->get();
        $breakdown = $this->loyalty->lotBreakdown($lots->pluck('id')->all());
        $lots = $lots->filter(fn (Entry $lot) => $breakdown[$lot->id]['redeemed'] > 0)->values();

        return response()->json([
            'consumed_points_total' => $lots->sum(fn (Entry $lot) => $breakdown[$lot->id]['redeemed']),
            'orders' => $lots->map(fn (Entry $lot) => $this->orderRow($lot, $breakdown[$lot->id], $breakdown[$lot->id]['redeemed']) + [
                'redeemed_at' => ($lot->redeemed_at ?? $lot->updated_at)?->toDateTimeString(),
            ]),
        ]);
    }

    private function orderRow(Entry $lot, array $breakdown, int $points): array
    {
        return [
            'order_id' => $lot->order_id,
            'display_id' => "طلب #{$lot->order_id}",
            'points_earned' => $points,
            'created_at' => ($lot->order?->created_at ?? $lot->occurred_at)->toDateTimeString(),
            'points_original' => (int) $lot->points,
            'points_reversed' => $breakdown['reversed'],
        ];
    }

    // ------------------------------------------------------------ loyalty programme

    /** Balance, pending, expiring soon, lifetime totals and the programme rules. */
    public function balance(Request $request)
    {
        return response()->json([
            'data' => $this->loyalty->summary($request->user()->id),
            'rules' => $this->loyalty->settings()->toArray(),
        ]);
    }

    public function history(PointsHistoryRequest $request)
    {
        $entries = $request->apply(Entry::where('user_id', $request->user()->id))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($request->perPage())->withQueryString();

        return LoyaltyEntryResource::collection($entries);
    }

    public function expiring(Request $request)
    {
        $days = min(365, max(1, (int) $request->query('days', $this->loyalty->settings()->expiryWarningDays())));
        $lots = $this->loyalty->expiringLots($request->user()->id, $days)->limit(100)->get();

        return response()->json([
            'days' => $days,
            'points' => (int) $lots->sum('remaining_points'),
            'data' => LoyaltyEntryResource::collection($lots),
        ]);
    }

    public function rewards(Request $request)
    {
        $balance = $this->loyalty->ledger()->balance($request->user()->id);
        $rewards = LoyaltyReward::available()->orderBy('sort_order')->orderBy('points_required')
            ->paginate(min(50, max(1, (int) $request->query('per_page', 20))))->withQueryString();
        $rewards->getCollection()->each(fn (LoyaltyReward $reward) => $reward->affordable = $balance >= $reward->points_required);

        return RewardResource::collection($rewards)->additional(['balance' => $balance]);
    }

    public function reward(Request $request, int $reward)
    {
        $model = LoyaltyReward::available()->findOrFail($reward);
        $model->affordable = $this->loyalty->ledger()->balance($request->user()->id) >= $model->points_required;

        return new RewardResource($model);
    }

    public function redeemReward(Request $request, int $reward)
    {
        $data = $request->validate([
            'quantity' => 'nullable|integer|min:1|max:100',
            // Same key = same redemption: a retried request never spends points twice.
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $model = LoyaltyReward::findOrFail($reward);
        $key = $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);

        try {
            $redemption = $this->redemptions->redeem($request->user(), $model, (int) ($data['quantity'] ?? 1), null, $key);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'redemption_refused'], 422);
        }

        return (new RedemptionResource($redemption->fresh()))
            ->additional(['message' => api_trans('points.reward_redeemed'), 'balance' => $this->loyalty->ledger()->balance($request->user()->id)])
            ->response()->setStatusCode($redemption->wasRecentlyCreated ? 201 : 200);
    }

    public function redemptions(Request $request)
    {
        $request->validate(['status' => ['nullable', \Illuminate\Validation\Rule::in(LoyaltyRedemption::STATUSES)]]);

        $redemptions = LoyaltyRedemption::with('reward')->where('user_id', $request->user()->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();

        return RedemptionResource::collection($redemptions);
    }

    public function redemption(Request $request, int $redemption)
    {
        return new RedemptionResource(LoyaltyRedemption::with('reward')->where('user_id', $request->user()->id)->findOrFail($redemption));
    }
}
