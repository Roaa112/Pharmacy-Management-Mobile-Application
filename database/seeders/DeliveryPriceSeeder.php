<?php

namespace Database\Seeders;

use App\Models\DeliveryPrice;
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

        foreach ($prices as $governorate => $price) {
            DeliveryPrice::firstOrCreate(['governorate' => $governorate], ['price' => $price]);
        }
    }
}
