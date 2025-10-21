<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Admin;
use Illuminate\Database\Seeder;
use Database\Seeders\ProjectSeeder;

class DatabaseSeeder extends Seeder
{

   
    public function run(): void
    {
            $this->call([
             
                CitySeeder::class,
                AreaSeeder::class,
                AdminSeeder::class
            ]);
    
    }
}
