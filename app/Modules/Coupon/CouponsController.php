<?php

namespace App\Modules\Coupon;

use App\Models\Coupon;
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
    $coupons = Coupon::withCount(['orders' => fn ($q) => $q->where('status', '!=', 'canceled')])
        ->latest()
        ->paginate(25);

    return view('dashboard.Coupons.index', [
        'coupons' => $coupons,
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


    public function destroy($id)
    {
        $this->couponService->deleteCoupon($id);
        return redirect()->back()->with('success', 'Coupon deleted successfully!');
    }
}
