<?php

namespace App\Modules\Coupon\Repositories;

use Carbon\Carbon;
use App\Models\Coupon;
use App\Modules\Shared\Repositories\BaseRepository;

class CouponsRepository extends BaseRepository
{
    public function __construct(private Coupon $model)
    {
        parent::__construct($model);
    }

    public function create($attributes): Coupon
    {
        if (isset($attributes['start_at']) && $this->isValidDate($attributes['start_at'])) {
            $attributes['start_at'] = Carbon::parse($attributes['start_at'])->toDateTimeString();
        }

        if (isset($attributes['end_at']) && $this->isValidDate($attributes['end_at'])) {
            $attributes['end_at'] = Carbon::parse($attributes['end_at'])->toDateTimeString();
        }

       
        $coupon = $this->model->create($attributes);

        

        return $coupon;
    }


    public function update($id, array $attributes)
    {
        if (isset($attributes['start_at']) && $this->isValidDate($attributes['start_at'])) {
            $attributes['start_at'] = Carbon::parse($attributes['start_at'])->toDateTimeString();
        }
    
        if (isset($attributes['end_at']) && $this->isValidDate($attributes['end_at'])) {
            $attributes['end_at'] = Carbon::parse($attributes['end_at'])->toDateTimeString();
        }
    
        $model = $this->model->find($id); // ✅ Correct
    
        if (!$model) {
            return null;
        }
    
        $model->update($attributes); // ✅ Works on single model
    
        return $model;
    }
    

   
    private function isValidDate($date): bool
    {
        return (bool)strtotime($date);
    }
}
