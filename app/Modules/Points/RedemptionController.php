<?php

namespace App\Modules\Points;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\User;
use App\Modules\Points\Services\RewardRedemptionService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Loyalty › Redemptions: history, and the hand-over workflow (loyalty.manage to act). */
class RedemptionController extends Controller
{
    public function __construct(private RewardRedemptionService $redemptions)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(LoyaltyRedemption::STATUSES)],
            'source' => ['nullable', Rule::in([LoyaltyRedemption::SOURCE_REWARD, LoyaltyRedemption::SOURCE_CHECKOUT])],
        ]);
        $search = trim((string) $request->query('search'));

        $redemptions = LoyaltyRedemption::with(['user:id,name,email', 'reward:id,title,code', 'creator:id,name', 'canceller:id,name', 'fulfiller:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('source'), fn ($q, $source) => $q->where('source', $source))
            ->when($request->query('reward_id'), fn ($q, $id) => $q->where('reward_id', (int) $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->latest('id')->paginate(30)->withQueryString();

        return view('dashboard.loyalty.redemptions', [
            'redemptions' => $redemptions,
            'counts' => LoyaltyRedemption::groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status'),
            'search' => $search,
        ]);
    }

    /** Staff redeem a reward for a customer (at the counter). */
    public function store(Request $request, User $customer)
    {
        $data = $request->validate([
            'reward_id' => ['required', 'integer', Rule::exists('loyalty_rewards', 'id')->whereNull('deleted_at')],
            'quantity' => 'nullable|integer|min:1|max:100',
            'fulfil_now' => 'nullable|boolean',
            'request_key' => 'nullable|string|max:64',
        ]);

        try {
            $redemption = $this->redemptions->redeem($customer, LoyaltyReward::findOrFail($data['reward_id']), (int) ($data['quantity'] ?? 1),
                auth('admin')->user(), $data['request_key'] ?? null, $request->boolean('fulfil_now'));
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Redemption {$redemption->number} recorded ({$redemption->points} points).");
    }

    public function approve(LoyaltyRedemption $redemption)
    {
        return $this->act(fn () => $this->redemptions->approve($redemption, auth('admin')->user()), 'Redemption approved.');
    }

    public function fulfil(LoyaltyRedemption $redemption)
    {
        return $this->act(fn () => $this->redemptions->fulfil($redemption, auth('admin')->user()), 'Redemption fulfilled.');
    }

    public function cancel(Request $request, LoyaltyRedemption $redemption)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:250']);

        return $this->act(fn () => $this->redemptions->cancel($redemption, auth('admin')->user(), $data['reason']), 'Redemption cancelled: stock and points returned.');
    }

    private function act(callable $action, string $message)
    {
        try {
            $action();
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', $message);
    }
}
