<?php

namespace App\Modules\Coupon\Services;

use App\Models\Coupon;

use App\Modules\Coupon\Resources\CouponCollection;
use App\Modules\Coupon\Repositories\CouponsRepository;
use App\Modules\Coupon\Requests\ListAllCouponsRequest;

class CouponService
{
    public function __construct(private CouponsRepository $couponsRepository)
    {
    }
  
    public function createCoupon($request)
    {
        $coupon = $this->constructCouponModel($request);
        return $this->couponsRepository->create($coupon);
    }

    public function updateCoupon($id, $request)
    {
      
      
        $coupon = $this->constructCouponModel($request); 
    
        return $this->couponsRepository->update($id, $coupon);
    }

    public function deleteCoupon($id)
    {
        return $this->couponsRepository->delete($id);
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
        $couponModel = [
            'code' => $request['code'],
            'discount_value' => $request['discount_value'],
            'is_active' => $request['is_active'] ?? false,
            'start_at' => $request['start_at'],
            'end_at' => $request['end_at'],
            'usage_limit' => $request['usage_limit'],
            'used_count' => $request['used_count'] ?? 0,
            'once_per_user' => $request['once_per_user'] ?? false,
        ];
    
        return $couponModel;
    }
    
    
   
  
}
