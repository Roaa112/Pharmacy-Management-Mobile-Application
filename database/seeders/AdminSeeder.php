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
        $email = env('Admin_Email');
        $password = env('Admin_Password');

        if (!$email || !$password) {
            $this->command?->warn('AdminSeeder skipped: set Admin_Email and Admin_Password in .env.');

            return;
        }

        // An existing admin with this email is left as it is (its password is not reset).
        Admin::firstOrCreate(['email' => $email], [
            'name' => 'Super Admin',
            'phone' => '1234567890',
            'password' => $password,
        ]);
    }
}
