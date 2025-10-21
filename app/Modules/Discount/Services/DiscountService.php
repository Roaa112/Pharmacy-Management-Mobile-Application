<?php

namespace App\Modules\Discount\Services;

use App\Models\Discount;

use Illuminate\Support\Arr;
use App\Models\DiscountRuleTarget;
use Illuminate\Support\Facades\DB;
use App\Modules\Discount\Resources\DiscountCollection;
use App\Modules\Discount\Repositories\DiscountsRepository;
use App\Modules\Discount\Requests\ListAllDiscountsRequest;

class DiscountService
{
    public function __construct(private DiscountsRepository $discountsRepository)
    {
    }

    public function getAll()
    {
        return $this->discountsRepository->getModel()->with('targets')->latest()->get();
    }

    public function getById($id)
    {
        return $this->discountsRepository->getModel()->with('targets')->findOrFail($id);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {

            $ruleData = $this->filterRuleData($data);


            $discount = $this->discountsRepository->create($ruleData);


            foreach ($data['targets'] ?? [] as $target) {

                DiscountRuleTarget::create([
                    'discount_rule_id' => $discount->id,
                    'target_type'      => $target['type'],
                    'target_id'        => $target['id'],
                    'is_gift'          => $target['is_gift'] ?? 0,
                ]);
            }

           
            foreach ($data['gift_targets'] ?? [] as $giftTarget) {

                DiscountRuleTarget::create([
                    'discount_rule_id' => $discount->id,
                    'target_type'      => $giftTarget['type'],
                    'target_id'        => $giftTarget['id'],
                    'is_gift'          => $giftTarget['is_gift'] ?? 1,
                ]);
            }

            return $discount;
        });
    }



    public function update($id, array $data)
    {


        $discount = $this->filterRuleData($data);

        return $this->discountsRepository->update($id, $data);

    }

    public function delete($id)
    {
        return $this->discountsRepository->delete($id);
    }

    // private function filterRuleData(array $data): array
    // {

    // return Arr::only($data, [
    //     'discount_type',
    //     'discount_value',
    //     'min_amount',
    //     'min_quantity',
    //     'free_quantity',
    //     'gift_type',

    //     'gift_id',

    //     'gift_quantity',
    //     'starts_at',
    //     'ends_at',
    //     'applies_to_type',
    //     'applies_to_id',
    // ]);

    // }


    private function filterRuleData(array $data): array
{
    return Arr::only($data, [
        'discount_type',
        'discount_value',
        'min_amount',
        'min_quantity',
        'free_quantity',
        'gift_type',
        'gift_id',

        'gift_quantity',
        'starts_at',
        'ends_at',
        'applies_to_type',
        'applies_to_id',
    ]);
}

}




