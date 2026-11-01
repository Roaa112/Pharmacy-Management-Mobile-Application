<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\DeliveryPrice;
use App\Models\DeliveryZone;
use App\Models\DeliveryZoneLocation;
use Illuminate\Database\Seeder;

/**
 * Delivery fee per governorate (names match CitySeeder and the "governorate" of addresses).
 * Existing rows are kept as they are, so prices the admin changed are never overwritten.
 */
class DeliveryPriceSeeder extends Seeder
{
    public function run(): void
    {
        $prices = [
            'Cairo' => 50,
            'Giza' => 50,
            'Qaliubiya' => 60,
            'Alexandria' => 70,
            'Dakahlia' => 80,
            'Sharkia' => 80,
            'Gharbiya' => 80,
            'Menofia' => 80,
            'Ismailia' => 90,
            'Suez' => 90,
            'Port Said' => 90,
            'Luxor' => 110,
            'Aswan' => 120,
            'Red Sea' => 120,
        ];

        $country = Country::default();

        foreach ($prices as $governorate => $price) {
            // Legacy table, kept for reference; checkout prices through zones and rates.
            DeliveryPrice::firstOrCreate(['governorate' => $governorate], ['price' => $price]);

            // One zone per governorate covering the city of that name, as the migration did for
            // existing prices. Zones the admin already has are left alone.
            $city = City::named($governorate)->first() ?? City::create(['name' => $governorate, 'country_id' => $country?->id]);
            if (DeliveryZoneLocation::where('city_id', $city->id)->whereNull('area_id')->exists()) {
                continue;
            }

            $zone = DeliveryZone::create(['country_id' => $city->country_id ?? $country?->id, 'name' => $governorate]);
            $zone->locations()->create(['city_id' => $city->id]);
            $zone->rates()->create(['fee' => $price, 'eta_min_days' => 1, 'eta_max_days' => 3]);
        }
    }
}
