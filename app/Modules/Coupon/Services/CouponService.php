<?php

namespace App\Modules\Coupon\Services;

use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\User;
use App\Modules\Coupon\Resources\CouponCollection;
use App\Modules\Coupon\Repositories\CouponsRepository;
use App\Modules\Coupon\Requests\ListAllCouponsRequest;
use Illuminate\Support\Facades\DB;

class CouponService
{
    public function __construct(private CouponsRepository $couponsRepository)
    {
    }

    public function createCoupon($request)
    {
        return DB::transaction(function () use ($request) {
            $coupon = Coupon::create($this->constructCouponModel($request) + ['used_count' => 0]);
            $this->syncScope($coupon, $request);
            AdminAuditLog::record('coupon.created', $coupon, $coupon->toArray());

            return $coupon;
        });
    }

    public function updateCoupon($id, $request)
    {
        return DB::transaction(function () use ($id, $request) {
            $coupon = Coupon::findOrFail($id);
            $before = $coupon->toArray();
            // used_count is never edited: it moves only with redemptions (PromotionLifecycle).
            $coupon->update($this->constructCouponModel($request));
            $this->syncScope($coupon, $request);
            AdminAuditLog::record('coupon.updated', $coupon, ['before' => $before, 'after' => $coupon->fresh()->toArray()]);

            return $coupon;
        });
    }

    /** Archive (soft delete): orders and redemptions keep the coupon and its code. */
    public function deleteCoupon($id)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->update(['is_active' => false]);
        $coupon->delete();
        AdminAuditLog::record('coupon.archived', $coupon);

        return true;
    }

    public function listAllCoupons(array $queryParameters)
    {

        $listAllCoupons= (new ListAllCouponsRequest)->constructQueryCriteria($queryParameters);
        $coupons= $this->couponsRepository->findAllBy($listAllCoupons );

        return [
            'data' => new CouponCollection($coupons['data']),
            'count' => $coupons['count']
        ];
    }

    public function getCouponById($id)
    {
        return $this->couponsRepository->find($id);
    }

    public function constructCouponModel($request)
    {
        return [
            'code' => trim($request['code']),
            'name' => $request['name'] ?? null,
            'discount_value' => $request['discount_value'],
            'is_active' => $request['is_active'] ?? false,
            'start_at' => $request['start_at'],
            'end_at' => $request['end_at'],
            'usage_limit' => $request['usage_limit'] ?? null,
            'once_per_user' => $request['once_per_user'] ?? false,
            'type' => $request['type'] ?? 'percent',
            'min_order_amount' => $request['min_order_amount'] ?? null,
            'max_discount_amount' => $request['max_discount_amount'] ?? null,
            'per_user_limit' => $request['per_user_limit'] ?? null,
            // Empty = follow the store setting (settings.coupon_applies_to_delivery).
            'applies_to_delivery' => isset($request['applies_to_delivery']) && $request['applies_to_delivery'] !== '' ? (bool) $request['applies_to_delivery'] : null,
            'first_order_only' => (bool) ($request['first_order_only'] ?? false),
            'combines_with_promotions' => (bool) ($request['combines_with_promotions'] ?? false),
            'customer_scope' => $request['customer_scope'] ?? 'all',
        ];
    }

    /** Product/category/brand scope and the customer list, replaced as a whole. */
    private function syncScope(Coupon $coupon, $request): void
    {
        $coupon->targets()->delete();
        foreach (['target_categories' => 'category', 'target_brands' => 'brand', 'target_products' => 'product'] as $field => $type) {
            foreach ($request[$field] ?? [] as $id) {
                $coupon->targets()->create(['target_type' => $type, 'target_id' => (int) $id, 'is_excluded' => false]);
            }
        }
        foreach ($request['excluded_products'] ?? [] as $id) {
            $coupon->targets()->create(['target_type' => 'product', 'target_id' => (int) $id, 'is_excluded' => true]);
        }

        $emails = preg_split('/[\s,;]+/', (string) ($request['customer_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $coupon->customers()->sync(($request['customer_scope'] ?? 'all') === 'specific' ? User::whereIn('email', $emails)->pluck('id')->all() : []);
    }
}
