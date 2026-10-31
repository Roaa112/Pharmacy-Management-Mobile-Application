<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Demo\CatalogSeeder;
use Database\Seeders\Demo\CustomerSeeder;
use Database\Seeders\Demo\HealthDataSeeder;
use Database\Seeders\Demo\MedicationSeeder;
use Database\Seeders\Demo\OrderSeeder;
use Database\Seeders\Demo\PromotionSeeder;
use Database\Seeders\Demo\ReturnSeeder;
use Illuminate\Database\Seeder;

/**
 * Demo data for development and testing: a full catalogue, promotions, customers, orders,
 * returns and health tracker data. Never runs in production, and runs only once per database
 * (it stops if the demo customers already exist).
 *
 *   php artisan migrate:fresh --seed            (base + demo data)
 *   php artisan db:seed --class=DemoSeeder      (demo data only)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder skipped: demo data is never seeded in production.');

            return;
        }

        if (User::whereIn('email', array_keys(CustomerSeeder::CUSTOMERS))->exists()) {
            $this->command?->warn('DemoSeeder skipped: demo data is already present.');

            return;
        }

        $this->call([
            SettingSeeder::class,
            DeliveryPriceSeeder::class,
            CatalogSeeder::class,
            PromotionSeeder::class,
            CustomerSeeder::class,
            OrderSeeder::class,
            ReturnSeeder::class,
            HealthDataSeeder::class,
            MedicationSeeder::class,
        ]);
    }
}
