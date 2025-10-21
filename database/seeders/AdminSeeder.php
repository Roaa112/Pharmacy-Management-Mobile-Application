<?php

namespace Database\Seeders;
use App\Models\Admin;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Admin::create([
            'name' => 'Super Admin',
            'email' => env('Admin_Email'),
            'phone' => '1234567890',
            'password' => env('Admin_Password'), 
        ]);
    }
}
