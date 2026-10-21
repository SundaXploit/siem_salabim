<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use LogicException;

class DefaultUsersSeeder extends Seeder
{
    public function run(): void
    {
        throw new LogicException(
            'DefaultUsersSeeder dinonaktifkan karena kredensial bawaan tidak aman. Gunakan wizard installer atau siem:create-admin.'
        );
    }
}
