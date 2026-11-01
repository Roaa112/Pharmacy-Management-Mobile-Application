<?php

namespace App\Modules\Coupon;

use App\Models\AdminAuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Coupon\Services\CouponService;
use App\Modules\Coupon\Requests\StoreCouponRequest;
use App\Modules\Coupon\Requests\UpdateCouponRequest;

class CouponsController extends Controller
{
    public function __construct(private CouponService $couponService)
    {
    }

    public function index(Request $request)
    {
        // Viewing the list no longer switches coupons off: that disabled scheduled (future) coupons
        // for good. Checkout already enforces start/end dates; the page shows the state instead.
        $coupons = Coupon::with(['targets', 'customers:id,email'])
            ->withCount(['orders' => fn ($q) => $q->where('status', '!=', 'canceled')])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('code', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $request->q).'%')->orWhere('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $request->q).'%')))
            ->when($request->query('status') === 'archived', fn ($q) => $q->onlyTrashed())
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('dashboard.Coupons.index', [
            'coupons' => $coupons,
            'categories' => Category::orderBy('name_en')->pluck('name_en', 'id'),
            'brands' => Brand::orderBy('name_en')->pluck('name_en', 'id'),
            'products' => Product::basic()->orderBy('name_en')->pluck('name_en', 'id'),
        ]);
    }

    public function show($id)
    {
        $coupon = Coupon::withTrashed()->with(['targets', 'customers:id,name,email'])->findOrFail($id);
        $redemptions = CouponRedemption::with(['order:id,status,total_price', 'user:id,name,email'])->where('coupon_id', $coupon->id)->latest('id')->paginate(20);

        return view('dashboard.Coupons.show', [
            'coupon' => $coupon,
            'redemptions' => $redemptions,
            'stats' => [
                'counting' => CouponRedemption::where('coupon_id', $coupon->id)->whereIn('status', CouponRedemption::COUNTING)->count(),
                'released' => CouponRedemption::where('coupon_id', $coupon->id)->where('status', CouponRedemption::RELEASED)->count(),
                'discount' => (float) CouponRedemption::where('coupon_id', $coupon->id)->whereIn('status', CouponRedemption::COUNTING)->sum('discount_amount'),
            ],
            'audit' => AdminAuditLog::with('admin:id,name')->where('subject_type', 'Coupon')->where('subject_id', $coupon->id)->latest('id')->limit(30)->get(),
            'names' => [
                'category' => Category::pluck('name_en', 'id'),
                'brand' => Brand::pluck('name_en', 'id'),
                'product' => Product::basic()->withTrashed()->pluck('name_en', 'id'),
            ],
        ]);
    }

    public function store(StoreCouponRequest $request)
    {
        $this->couponService->createCoupon($request->validated());
        return redirect()->back()->with('success', 'Coupon created successfully!');
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon)
    {
        $this->couponService->updateCoupon($coupon->id, $request->validated());
        return redirect()->back()->with('success', 'Coupon updated successfully!');
    }

    public function toggle(Coupon $coupon)
    {
        $coupon->update(['is_active' => !$coupon->is_active]);
        AdminAuditLog::record($coupon->is_active ? 'coupon.activated' : 'coupon.deactivated', $coupon);

        return back()->with('success', $coupon->is_active ? 'Coupon activated.' : 'Coupon deactivated.');
    }

    public function destroy($id)
    {
        $this->couponService->deleteCoupon($id);
        return redirect()->back()->with('success', 'Coupon archived. Orders that used it keep their totals.');
    }
}
