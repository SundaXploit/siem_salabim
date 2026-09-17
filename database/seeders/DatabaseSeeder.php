<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Never publish shared login credentials or reset existing accounts.
        $this->command?->info('Buat administrator melalui: php artisan siem:create-admin');
    }
}
