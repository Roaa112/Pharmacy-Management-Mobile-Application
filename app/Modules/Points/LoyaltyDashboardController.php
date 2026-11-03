<?php

namespace App\Modules\Points;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyLedgerEntry as Entry;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\User;
use App\Modules\Points\Requests\PointsHistoryRequest;
use App\Modules\Points\Services\LoyaltyService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Loyalty › Overview, Customers, Transactions, Expiring, and manual adjustments. */
class LoyaltyDashboardController extends Controller
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    public function overview()
    {
        $byType = Entry::groupBy('type')->selectRaw('type, sum(points) as total, count(*) as entries')->get()->keyBy('type');
        $total = fn (string $type) => (int) ($byType[$type]->total ?? 0);
        $warningDays = $this->loyalty->settings()->expiryWarningDays();

        return view('dashboard.loyalty.overview', [
            'stats' => [
                'active' => (int) Entry::usable()->sum('remaining_points'),
                'pending' => (int) Entry::lots()->where('status', Entry::STATUS_PENDING)->sum('remaining_points'),
                'earned' => $total(Entry::EARNED) + $total(Entry::BONUS) + (int) Entry::where('type', Entry::ADJUSTED)->where('points', '>', 0)->sum('points'),
                'redeemed' => -$total(Entry::REDEEMED) - $total(Entry::CANCELLED),
                'expired' => -$total(Entry::EXPIRED),
                'reversed' => -$total(Entry::REVERSED) - $total(Entry::REFUNDED),
                'expiring' => (int) $this->loyalty->expiringLots(null, $warningDays)->sum('remaining_points'),
                'rewards' => LoyaltyReward::count(),
                'rewards_active' => LoyaltyReward::available()->count(),
                'redemptions' => LoyaltyRedemption::count(),
                'redemptions_open' => LoyaltyRedemption::whereIn('status', LoyaltyRedemption::OPEN)->count(),
            ],
            'warningDays' => $warningDays,
            'recent' => Entry::with(['user:id,name', 'admin:id,name'])->latest('id')->limit(10)->get(),
            'topCustomers' => Entry::usable()->groupBy('user_id')->selectRaw('user_id, sum(remaining_points) as balance')
                ->orderByDesc('balance')->limit(10)->with('user:id,name,email')->get(),
            'settings' => $this->loyalty->settings(),
        ]);
    }

    /** Customers with their usable and pending points (computed in SQL, one page loaded). */
    public function customers(Request $request)
    {
        $search = trim((string) $request->query('search'));
        $usable = Entry::usable()->whereColumn('loyalty_ledger.user_id', 'users.id')->selectRaw('coalesce(sum(remaining_points), 0)');
        $pending = Entry::lots()->where('status', Entry::STATUS_PENDING)->whereColumn('loyalty_ledger.user_id', 'users.id')->selectRaw('coalesce(sum(remaining_points), 0)');

        $customers = User::query()->select('id', 'name', 'email', 'phone')
            ->selectSub($usable, 'points_balance')
            ->selectSub($pending, 'points_pending')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->when(!$request->boolean('all'), fn ($q) => $q->whereExists(fn ($e) => $e->from('loyalty_ledger')->whereColumn('loyalty_ledger.user_id', 'users.id')))
            ->orderByDesc('points_balance')->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('dashboard.loyalty.customers', ['customers' => $customers, 'search' => $search]);
    }

    public function transactions(PointsHistoryRequest $request)
    {
        $search = trim((string) $request->query('customer'));

        $entries = $request->apply(Entry::query())
            ->with(['user:id,name,email', 'admin:id,name'])
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($request->query('order_id'), fn ($q, $id) => $q->where('order_id', (int) $id))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($request->perPage(50))->withQueryString();

        return view('dashboard.loyalty.transactions', ['entries' => $entries, 'types' => Entry::TYPES]);
    }

    public function expiring(Request $request)
    {
        $days = min(365, max(1, (int) $request->query('days', $this->loyalty->settings()->expiryWarningDays())));

        return view('dashboard.loyalty.expiring', [
            'days' => $days,
            'upcoming' => $this->loyalty->expiringLots(null, $days)->with('user:id,name,email')->paginate(50, ['*'], 'upcoming_page')->withQueryString(),
            'upcomingTotal' => (int) $this->loyalty->expiringLots(null, $days)->sum('remaining_points'),
            'expired' => Entry::where('type', Entry::EXPIRED)->with('user:id,name,email')->latest('occurred_at')->paginate(25, ['*'], 'expired_page')->withQueryString(),
        ]);
    }

    /** Add or remove points by hand (loyalty.adjust_points). JSON for API clients, redirect otherwise. */
    public function adjust(Request $request, User $customer)
    {
        $data = $request->validate([
            'points' => 'required|integer|not_in:0|between:-1000000,1000000',
            'reason' => 'required|string|min:3|max:255',
            'type' => ['nullable', Rule::in([Entry::ADJUSTED, Entry::BONUS])],
            'valid_days' => 'nullable|integer|min:1|max:3650',
            // A random value from the form: a double submit records the adjustment once.
            'request_key' => 'nullable|string|max:64',
        ]);

        try {
            $entry = $this->loyalty->adjust($customer, (int) $data['points'], $data['reason'], auth('admin')->user(),
                $data['type'] ?? Entry::ADJUSTED, $data['valid_days'] ?? null, $data['request_key'] ?? null);
        } catch (DomainException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        $balance = $this->loyalty->ledger()->balance($customer->id);

        return $request->expectsJson()
            ? response()->json(['message' => 'Points adjusted.', 'entry_id' => $entry->id, 'balance' => $balance], 201)
            : redirect()->back()->with('success', 'Points adjusted. New balance: '.number_format($balance).'.');
    }
}
