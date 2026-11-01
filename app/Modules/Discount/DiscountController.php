<?php

namespace App\Modules\Discount;

use App\Models\AdminAuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\DiscountRule;
use App\Models\OrderPromotion;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\PromotionCampaign;
use App\Models\PromotionRedemption;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Discount\Requests\SavePromotionRequest;
use App\Modules\Promotions\Services\PromotionAdminService;
use App\Modules\Promotions\Services\PromotionSimulator;
use App\Support\StoreClock;
use Illuminate\Support\Carbon;

/**
 * Marketing → Promotions: percentage and fixed discounts, Buy X get Y, Spend X get Y and free
 * delivery. List with search and filters, create, edit, view (configuration check, covered
 * products, usage, analytics, audit), activate/deactivate, duplicate, archive, and a preview
 * that runs the real engine on a sample cart.
 */
class DiscountController extends Controller
{
    /** Dashboard sections: one per business type (percent and fixed share "Discounts"). */
    public const SECTIONS = [
        'discounts' => ['Discounts', [DiscountRule::TYPE_PERCENT, DiscountRule::TYPE_FIXED], 'discount'],
        'buy_x_get_y' => ['Buy X get Y', [DiscountRule::TYPE_BUY_X_GET_Y], 'gift'],
        'amount_gift' => ['Spend X get Y', [DiscountRule::TYPE_SPEND_X_GET_Y], 'gift-card'],
        'free_delivery' => ['Free delivery', [DiscountRule::TYPE_FREE_DELIVERY], 'truck-delivery'],
    ];

    public function __construct(private PromotionAdminService $promotions, private PromotionSimulator $simulator)
    {
    }

    public function index(Request $request)
    {
        $section = array_key_exists($request->query('section'), self::SECTIONS) ? $request->query('section') : null;
        $now = StoreClock::now()->toDateTimeString();

        $query = DiscountRule::with(['targets', 'rewards.product', 'campaign'])
            ->withCount(['redemptions as uses_count' => fn ($q) => $q->whereIn('status', PromotionRedemption::COUNTING)])
            ->when($section, fn ($q) => $q->whereIn('discount_type', self::SECTIONS[$section][1]))
            ->when($request->filled('campaign'), fn ($q) => $q->where('campaign_id', $request->integer('campaign')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->query('q')).'%';
                $q->where(fn ($q) => $q->where('name_en', 'like', $term)->orWhere('name_ar', 'like', $term)->orWhere('id', (int) $request->query('q')));
            })
            ->when($request->query('status') === 'archived', fn ($q) => $q->onlyTrashed())
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true)->where('starts_at', '<=', $now)->where('ends_at', '>=', $now))
            ->when($request->query('status') === 'scheduled', fn ($q) => $q->where('is_active', true)->where('starts_at', '>', $now))
            ->when($request->query('status') === 'expired', fn ($q) => $q->where('ends_at', '<', $now))
            ->orderByDesc('priority')->orderByDesc('id');

        return view('dashboard.Discounts.index', [
            'discounts' => $query->paginate(25)->withQueryString(),
            'section' => $section,
            'sections' => self::SECTIONS,
            'campaigns' => PromotionCampaign::orderBy('name_en')->pluck('name_en', 'id'),
            'lookups' => $this->lookups(),
        ]);
    }

    public function create(Request $request)
    {
        $type = in_array($request->query('type'), DiscountRule::TYPES, true) ? $request->query('type') : DiscountRule::TYPE_PERCENT;

        return view('dashboard.Discounts.form', $this->formData(new DiscountRule([
            'discount_type' => $type, 'is_active' => false, 'priority' => 0, 'customer_scope' => 'all',
            'qualifying_basis' => 'discounted', 'coupon_reduces_qualifying' => true, 'tier_mode' => 'highest', 'gift_fallback' => 'partial',
        ])));
    }

    public function store(SavePromotionRequest $request)
    {
        $promotion = $this->promotions->save($request->promotionData(), null, auth('admin')->id());

        return redirect()->route('dashboard.discounts.show', $promotion->id)->with('success', 'Promotion created.');
    }

    public function show($id)
    {
        $promotion = DiscountRule::withTrashed()->with(['targets', 'rewards.product.sizes', 'rewards.size', 'campaign', 'customers:id,name,email'])->findOrFail($id);

        return view('dashboard.Discounts.show', $this->showData($promotion));
    }

    public function edit($id)
    {
        $promotion = DiscountRule::with(['targets', 'rewards', 'customers:id,email'])->findOrFail($id);

        return view('dashboard.Discounts.form', $this->formData($promotion));
    }

    public function update(SavePromotionRequest $request, $id)
    {
        $promotion = DiscountRule::findOrFail($id);
        $this->promotions->save($request->promotionData(), $promotion, auth('admin')->id());

        return redirect()->route('dashboard.discounts.show', $promotion->id)->with('success', 'Promotion saved.');
    }

    public function toggle($id)
    {
        $promotion = DiscountRule::findOrFail($id);
        $this->promotions->setActive($promotion, !$promotion->is_active, auth('admin')->id());

        return back()->with('success', $promotion->is_active ? 'Promotion activated.' : 'Promotion deactivated.');
    }

    public function duplicate($id)
    {
        $copy = $this->promotions->duplicate(DiscountRule::findOrFail($id), auth('admin')->id());

        return redirect()->route('dashboard.discounts.edit', $copy->id)->with('success', 'Copy created as an inactive draft.');
    }

    /** Preview: the real engine on a sample cart, nothing saved. */
    public function simulate(Request $request, $id)
    {
        $promotion = DiscountRule::withTrashed()->with(['targets', 'rewards.product.sizes', 'rewards.size', 'campaign', 'customers:id,name,email'])->findOrFail($id);

        $request->validate([
            'lines' => 'required|array|min:1|max:20',
            'lines.*.product_size_id' => 'nullable|integer|exists:product_sizes,id',
            'lines.*.quantity' => 'nullable|integer|min:1|max:1000',
            'customer_email' => 'nullable|email|exists:users,email',
            'coupon' => 'nullable|string|max:255',
            'delivery_fee' => 'nullable|numeric|min:0|max:100000',
            'at' => 'nullable|date',
        ]);

        $lines = [];
        foreach ($request->input('lines') as $line) {
            if (!empty($line['product_size_id']) && !empty($line['quantity'])) {
                $lines[(int) $line['product_size_id']] = ($lines[(int) $line['product_size_id']] ?? 0) + (int) $line['quantity'];
            }
        }

        $result = $this->simulator->simulate(
            $promotion,
            $lines,
            $request->filled('customer_email') ? User::where('email', $request->customer_email)->first() : null,
            $request->input('coupon'),
            $request->filled('delivery_fee') ? (float) $request->delivery_fee : null,
            $request->filled('at') ? Carbon::parse($request->at) : null,
        );

        return view('dashboard.Discounts.show', $this->showData($promotion) + ['simulation' => $result, 'simulationInput' => $request->all()]);
    }

    public function destroy($id)
    {
        $this->promotions->archive(DiscountRule::findOrFail($id));

        return redirect()->route('dashboard.discounts.index')->with('success', 'Promotion archived. Orders that used it keep their snapshot.');
    }

    private function showData(DiscountRule $promotion): array
    {
        $orderPromotions = OrderPromotion::where('discount_rule_id', $promotion->id);

        return [
            'promotion' => $promotion,
            'overview' => $this->simulator->overview($promotion),
            'redemptions' => PromotionRedemption::with(['order:id,status,total_price,created_at', 'user:id,name,email'])->where('discount_rule_id', $promotion->id)->latest('id')->paginate(15),
            'analytics' => [
                'orders' => (clone $orderPromotions)->count(),
                'active_uses' => PromotionRedemption::where('discount_rule_id', $promotion->id)->whereIn('status', PromotionRedemption::COUNTING)->count(),
                'released' => PromotionRedemption::where('discount_rule_id', $promotion->id)->where('status', PromotionRedemption::RELEASED)->count(),
                'discount' => (float) (clone $orderPromotions)->sum('discount_amount') + (float) (clone $orderPromotions)->sum('delivery_discount_amount'),
                'gift_units' => (int) (clone $orderPromotions)->sum('reward_quantity'),
                'revenue' => (float) \App\Models\Order::whereIn('id', (clone $orderPromotions)->select('order_id'))->where('status', '!=', 'canceled')->sum('total_price'),
            ],
            'audit' => AdminAuditLog::with('admin:id,name')->where('subject_type', 'DiscountRule')->where('subject_id', $promotion->id)->latest('id')->limit(30)->get(),
            'sizes' => $this->sizeOptions(),
            'defaultMoment' => $this->simulator->defaultMoment($promotion),
            'lookups' => $this->lookups(),
        ];
    }

    private function formData(DiscountRule $promotion): array
    {
        return [
            'promotion' => $promotion,
            'campaigns' => PromotionCampaign::orderBy('name_en')->pluck('name_en', 'id'),
            'lookups' => $this->lookups(),
            'sizes' => $this->sizeOptions(),
        ];
    }

    /** Names for target pickers (full lists: the listing services stop at 10 rows). */
    private function lookups(): array
    {
        return [
            'product' => Product::basic()->orderBy('name_en')->pluck('name_en', 'id'),
            'variant' => $this->sizeOptions()->mapWithKeys(fn ($s) => [$s->id => ($s->product?->name_en ?? 'Product').' — '.$s->size])->all(),
            'brand' => Brand::orderBy('name_en')->pluck('name_en', 'id'),
            'category' => Category::orderBy('name_en')->pluck('name_en', 'id'),
        ];
    }

    private $sizeOptions = null;

    private function sizeOptions()
    {
        return $this->sizeOptions ??= ProductSize::with(['product' => fn ($q) => $q->basic()])->whereHas('product')->orderBy('product_id')->orderBy('id')->get();
    }
}
