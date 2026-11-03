<?php

namespace App\Modules\Points;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\ProductSize;
use App\Modules\Points\Requests\RewardRequest;
use App\Support\MarketingImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Loyalty › Rewards: the catalog of what points buy (loyalty.manage_rewards to change it). */
class RewardController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));

        $rewards = LoyaltyReward::with('product:id,name_en,name_ar')
            ->withCount(['redemptions as open_redemptions' => fn ($q) => $q->whereIn('status', LoyaltyRedemption::OPEN)])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('status') === 'active', fn ($q) => $q->available())
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('sort_order')->orderBy('points_required')
            ->paginate(25)->withQueryString();

        return view('dashboard.loyalty.rewards.index', ['rewards' => $rewards, 'search' => $search, 'types' => LoyaltyReward::TYPES]);
    }

    public function create()
    {
        return $this->form(new LoyaltyReward(['type' => LoyaltyReward::TYPE_PRODUCT, 'is_active' => true, 'sort_order' => 0]));
    }

    public function edit(LoyaltyReward $reward)
    {
        return $this->form($reward);
    }

    public function store(RewardRequest $request)
    {
        $reward = DB::transaction(function () use ($request) {
            $reward = LoyaltyReward::create($this->attributes($request));
            AdminAuditLog::record('loyalty.reward_create', $reward, ['after' => $reward->toArray()]);

            return $reward;
        });

        return redirect()->route('dashboard.loyalty.rewards.index')->with('success', "Reward {$reward->title} created.");
    }

    public function update(RewardRequest $request, LoyaltyReward $reward)
    {
        DB::transaction(function () use ($request, $reward) {
            $reward = LoyaltyReward::lockForUpdate()->findOrFail($reward->id);
            $before = $reward->only($fields = array_keys($attributes = $this->attributes($request, $reward)));
            $reward->update($attributes);
            AdminAuditLog::record('loyalty.reward_update', $reward, ['before' => $before, 'after' => $reward->only($fields)]);
        });

        return redirect()->route('dashboard.loyalty.rewards.index')->with('success', 'Reward saved.');
    }

    public function toggle(LoyaltyReward $reward)
    {
        $reward->forceFill(['is_active' => !$reward->is_active])->save();
        AdminAuditLog::record($reward->is_active ? 'loyalty.reward_activate' : 'loyalty.reward_deactivate', $reward);

        return redirect()->back()->with('success', $reward->is_active ? 'Reward activated.' : 'Reward deactivated.');
    }

    /** Soft delete: redemptions keep pointing at it. Open redemptions must be settled first. */
    public function destroy(LoyaltyReward $reward)
    {
        if ($reward->redemptions()->whereIn('status', LoyaltyRedemption::OPEN)->exists()) {
            return redirect()->back()->with('error', 'Fulfil or cancel the open redemptions of this reward before deleting it.');
        }

        $reward->delete();
        AdminAuditLog::record('loyalty.reward_delete', $reward, ['before' => $reward->only(['code', 'title', 'points_required'])]);

        return redirect()->route('dashboard.loyalty.rewards.index')->with('success', 'Reward deleted.');
    }

    private function form(LoyaltyReward $reward)
    {
        return view('dashboard.loyalty.rewards.form', [
            'reward' => $reward,
            'types' => LoyaltyReward::TYPES,
            'sizes' => ProductSize::with('product:id,name_en,name_ar')->whereHas('product')->orderBy('product_id')->limit(2000)->get(),
        ]);
    }

    private function attributes(RewardRequest $request, ?LoyaltyReward $reward = null): array
    {
        $data = $request->validated();
        unset($data['image']);
        $data['is_active'] = $request->boolean('is_active');

        if (!empty($data['product_size_id'])) {
            $data['product_id'] = ProductSize::whereKey($data['product_size_id'])->value('product_id');
        } else {
            $data['product_size_id'] = null;
            $data['product_id'] = $data['type'] === LoyaltyReward::TYPE_PRODUCT ? ($data['product_id'] ?? null) : null;
        }

        if ($request->hasFile('image')) {
            $data['image'] = MarketingImage::store($request->file('image'), 'rewards');
        }

        return $data;
    }
}
