<?php

namespace App\Modules\Delivery\Requests;

use App\Models\Area;
use App\Models\DeliveryZoneLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeliveryZoneRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
            'city_ids' => 'nullable|array',
            'city_ids.*' => 'integer|exists:cities,id',
            'area_ids' => 'nullable|array',
            'area_ids.*' => 'integer|exists:areas,id',
        ];
    }

    /** A city or area can belong to one zone only; otherwise the fee would be ambiguous. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $zoneId = $this->route('zone')?->id;

            $taken = DeliveryZoneLocation::with('zone:id,name')
                ->when($zoneId, fn ($q) => $q->where('delivery_zone_id', '!=', $zoneId))
                ->where(function ($q) {
                    $q->where(fn ($q) => $q->whereIn('city_id', $this->input('city_ids', []))->whereNull('area_id'))
                        ->orWhereIn('area_id', $this->input('area_ids', []));
                })
                ->first();

            if ($taken) {
                $validator->errors()->add('city_ids', "A selected city or area already belongs to the zone \"{$taken->zone->name}\".");
            }

            $badArea = Area::whereIn('id', $this->input('area_ids', []))->with('city')->get()
                ->first(fn ($area) => $area->city?->country_id !== \App\Models\Country::default()?->id);
            if ($badArea) {
                $validator->errors()->add('area_ids', 'Every area must be an Egyptian area.');
            }
        });
    }
}
