<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    /** AreaSeeder refers to these by position: city_id 1 is the first entry. */
    public const CITIES = [
        'Cairo',
        'Giza',
        'Alexandria',
        'Dakahlia',
        'Red Sea',
        'Beheira',
        'Fayoum',
        'Gharbiya',
        'Ismailia',
        'Menofia',
        'Minya',
        'Qaliubiya',
        'New Valley',
        'Suez',
        'Aswan',
        'Assiut',
        'Beni Suef',
        'Port Said',
        'Damietta',
        'Sharkia',
        'South Sinai',
        'Kafr Al sheikh',
        'Matrouh',
        'Luxor',
        'Qena',
        'North Sinai',
        'Sohag',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // firstOrCreate: running the seeder again does not duplicate cities.
        foreach (self::CITIES as $name) {
            City::firstOrCreate(['name' => $name]);
        }
    }
}
