<?php

namespace Database\Seeders;

use App\Domain\User\Models\User;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        $admin = User::create([
            'name'     => 'Dr. Amit Gupta',
            'email'    => 'admin@taskflow.com',
            'phone'    => '+919876543210',
            'password' => Hash::make('Admin@123'),
            'role'     => 'admin',
            'department' => 'Management',
            'designation' => 'CEO',
            'is_active' => true,
        ]);

        // Manager
        $manager = User::create([
            'name'       => 'Aradhana Gupta',
            'email'      => 'manager@taskflow.com',
            'phone'      => '+919876543211',
            'password'   => Hash::make('Manager@123'),
            'role'       => 'manager',
            'department' => 'Operations',
            'designation' => 'Operations Head',
            'is_active'  => true,
        ]);

        // Employees
        $employees = [
            ['name' => 'Priya Sharma',  'phone' => '+919876543212', 'dept' => 'Sales',      'desig' => 'Field Executive'],
            ['name' => 'Rohan Mehta',   'phone' => '+919876543213', 'dept' => 'Sales',      'desig' => 'Sales Executive'],
            ['name' => 'Anita Joshi',   'phone' => '+919876543214', 'dept' => 'Operations', 'desig' => 'Lab Coordinator'],
            ['name' => 'Kiran Patel',   'phone' => '+919876543215', 'dept' => 'Finance',    'desig' => 'Collection Agent'],
            ['name' => 'Deepak Singh',  'phone' => '+919876543216', 'dept' => 'Marketing',  'desig' => 'Marketing Exec'],
        ];

        foreach ($employees as $emp) {
            User::create([
                'name'        => $emp['name'],
                'email'       => strtolower(str_replace(' ', '.', $emp['name'])) . '@taskflow.com',
                'phone'       => $emp['phone'],
                'password'    => Hash::make('Emp@123'),
                'role'        => 'employee',
                'department'  => $emp['dept'],
                'designation' => $emp['desig'],
                'is_active'   => true,
            ]);
        }

        // Sample team
        $team = Team::create([
            'name'        => 'Sales Team',
            'manager_id'  => $manager->id,
            'description' => 'Field sales and doctor visits',
        ]);

        echo "Seeded successfully!\n";
        echo "Admin: admin@taskflow.com / Admin@123\n";
        echo "Manager: manager@taskflow.com / Manager@123\n";
    }
}
