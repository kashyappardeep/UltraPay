<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Test user: email test@ultrapay.local, password Ultra@2026, PIN 4826
        $user = User::factory()->create([
            'name' => 'Test User',
            'mobile' => '9876543210',
            'email' => 'test@ultrapay.local',
        ]);
        $user->update(['hd_index' => $user->id]);
    }
}