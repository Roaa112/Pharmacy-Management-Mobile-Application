<?php

namespace App\Modules\Delivery\Services;

use App\Models\Address;
use App\Models\City;
use App\Models\Country;
use App\Models\DeliveryRate;
use App\Models\DeliveryZoneLocation;
use App\Support\Money;
use App\Support\StoreClock;

/**
 * Delivery fee for an address: address → city/area → zone → the effective rate with the
 * highest priority. Replaces the old substring match of the city against governorate names.
 *
 * Addresses saved before city_id existed are linked by an exact name match on the governorate
 * (then the city), and the link is stored on the address so it is resolved once.
 */
class DeliveryQuoteService
{
    public function quote(Address $address, int $subtotalMinor): DeliveryQuote
    {
        $cityId = $this->cityIdFor($address);

        if (!$cityId) {
            return DeliveryQuote::failed(DeliveryQuote::NO_ZONE);
        }

        $location = DeliveryZoneLocation::with('zone')
            ->where('city_id', $cityId)
            ->where(fn ($q) => $q->whereNull('area_id')->when($address->area_id, fn ($q) => $q->orWhere('area_id', $address->area_id)))
            ->whereHas('zone', fn ($q) => $q->where('is_active', true))
            // An area-specific zone wins over the zone of the whole city.
            ->orderByRaw('area_id is null')
            ->first();

        if (!$location) {
            return DeliveryQuote::failed(DeliveryQuote::NO_ZONE);
        }

        $rate = DeliveryRate::where('delivery_zone_id', $location->delivery_zone_id)
            ->effective(StoreClock::now())
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();

        if (!$rate) {
            return DeliveryQuote::failed(DeliveryQuote::NO_ZONE, $location->zone);
        }

        $minimumMinor = Money::toMinor($rate->min_order_amount);
        if ($minimumMinor > 0 && $subtotalMinor < $minimumMinor) {
            return DeliveryQuote::failed(DeliveryQuote::BELOW_MINIMUM, $location->zone, $rate, $minimumMinor);
        }

        $freeOverMinor = Money::toMinor($rate->free_over_amount);
        $free = $rate->free_over_amount !== null && $subtotalMinor >= $freeOverMinor;

        return new DeliveryQuote($location->zone, $rate, $free ? 0 : Money::toMinor($rate->fee), $free);
    }

    private function cityIdFor(Address $address): ?int
    {
        if ($address->city_id) {
            return $address->city_id;
        }

        $countryId = Country::default()?->id;
        $city = null;

        foreach ([$address->governorate, $address->city] as $name) {
            if (trim((string) $name) === '') {
                continue;
            }

            // Egyptian governorates only.
            $city = City::named($name)->where(fn ($q) => $q->whereNull('country_id')->orWhere('country_id', $countryId))->first();

            if ($city) {
                break;
            }
        }

        if ($city) {
            $address->forceFill(['city_id' => $city->id])->saveQuietly();
        }

        return $city?->id;
    }
}
