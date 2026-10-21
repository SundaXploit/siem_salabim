<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Production accounts are created explicitly by the installation
        // wizard or `siem:create-admin`. Never ship predictable credentials
        // through `migrate --seed`.
    }
}
