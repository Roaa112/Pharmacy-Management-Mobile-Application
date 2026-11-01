<?php

namespace App\Modules\Discount;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\OrderPromotion;
use App\Models\PromotionCampaign;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Marketing → Campaigns: a campaign groups promotions under shared dates and eligibility
 * (country, new/returning customers). A promotion applies only while its campaign runs too.
 */
class CampaignController extends Controller
{
    public function index(Request $request)
    {
        $campaigns = PromotionCampaign::withCount('promotions')
            ->when($request->filled('q'), fn ($q) => $q->where('name_en', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $request->q).'%'))
            ->latest('id')->paginate(25)->withQueryString();

        $stats = OrderPromotion::query()
            ->join('discount_rules', 'discount_rules.id', '=', 'order_promotions.discount_rule_id')
            ->whereIn('discount_rules.campaign_id', $campaigns->pluck('id'))
            ->groupBy('discount_rules.campaign_id')
            ->selectRaw('discount_rules.campaign_id, count(distinct order_promotions.order_id) as orders, sum(order_promotions.discount_amount + order_promotions.delivery_discount_amount) as discount')
            ->get()->keyBy('campaign_id');

        return view('dashboard.campaigns.index', ['campaigns' => $campaigns, 'stats' => $stats]);
    }

    public function create()
    {
        return redirect()->route('dashboard.campaigns.index');
    }

    public function store(Request $request)
    {
        $campaign = PromotionCampaign::create($this->validated($request));
        AdminAuditLog::record('campaign.created', $campaign, $campaign->toArray());

        return back()->with('success', 'Campaign created.');
    }

    public function edit(PromotionCampaign $campaign)
    {
        return redirect()->route('dashboard.campaigns.index');
    }

    public function update(Request $request, PromotionCampaign $campaign)
    {
        $before = $campaign->toArray();
        $campaign->update($this->validated($request));
        AdminAuditLog::record('campaign.updated', $campaign, ['before' => $before, 'after' => $campaign->fresh()->toArray()]);

        return back()->with('success', 'Campaign saved.');
    }

    /** Archive: its promotions stop applying (they need the campaign to run); history is kept. */
    public function destroy(PromotionCampaign $campaign)
    {
        $campaign->update(['is_active' => false]);
        $campaign->delete();
        AdminAuditLog::record('campaign.archived', $campaign);

        return back()->with('success', 'Campaign archived.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name_en' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'is_active' => 'nullable|boolean',
            'customer_scope' => ['nullable', Rule::in(['all', 'new', 'returning'])],
        ]);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['customer_scope'] ??= 'all';

        return $data;
    }
}
