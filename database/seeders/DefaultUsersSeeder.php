<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\UniqueConstraintViolationException;

class DefaultUsersSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Admin SOC',
                'email' => 'adminsoc@siem.local',
                'role' => 'admin',
            ],
            [
                'name' => 'Analis SOC',
                'email' => 'analissoc@siem.local',
                'role' => 'analyst',
            ],
        ];

        foreach ($accounts as $account) {
            // Seeding never resets an existing password or takes over an identity.
            $exists = User::query()
                ->whereRaw('LOWER(email) = ?', [$account['email']])
                ->exists();

            if ($exists) {
                $this->command?->info("Akun {$account['email']} dilewati: email sudah terdaftar.");

                continue;
            }

            try {
                // The User model hashes this explicitly requested starter password.
                User::create([...$account, 'password' => 'bhapp']);
            } catch (UniqueConstraintViolationException) {
                // Another seed process may have created this identity in the meantime.
                $this->command?->info("Akun {$account['email']} dilewati: email sudah terdaftar.");
            }
        }
    }
}
