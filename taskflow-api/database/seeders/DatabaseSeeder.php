<?php

namespace Database\Seeders;

use App\Domain\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Sirf ek admin user banao - real users WhatsApp se ya dashboard se add honge
        if (!User::where('email', 'admin@uicgroup.com')->exists()) {
            User::create([
                'name'              => 'Dr. Amit Gupta',
                'email'             => 'admin@uicgroup.com',
                'phone'             => '+919106959092',
                'password'          => Hash::make('UIC@2026'),
                'role'              => 'admin',
                'department'        => 'Management',
                'designation'       => 'Founder & CEO',
                'whatsapp_opted_in' => true,
                'is_active'         => true,
            ]);
            echo "✅ Admin created: admin@uicgroup.com / UIC@2026\n";
            echo "✅ WhatsApp linked: +919106959092\n";
        } else {
            echo "ℹ️  Admin already exists - skipping seed\n";
        }
    }
}
