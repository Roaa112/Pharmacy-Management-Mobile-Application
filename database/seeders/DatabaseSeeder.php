<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data every environment needs. Settings and delivery prices are only
        // created when missing, so re-running never overwrites what the admin changed.
        $this->call([
            CitySeeder::class,
            AreaSeeder::class,
            AdminSeeder::class,
            SettingSeeder::class,
            DeliveryPriceSeeder::class,
        ]);

        // Sample catalogue, customers, orders and health data (skipped in production).
        $this->call(DemoSeeder::class);
    }
}
