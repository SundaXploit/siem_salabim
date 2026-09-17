<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'siem:create-admin';

    protected $description = 'Buat administrator SIEM melalui prompt dengan password tersembunyi';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Jalankan perintah ini secara interaktif agar password tidak masuk riwayat shell.');

            return self::FAILURE;
        }

        $identity = [
            'name' => trim((string) $this->ask('Nama administrator')),
            'email' => strtolower(trim((string) $this->ask('Email administrator'))),
        ];

        $validator = Validator::make($identity, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ], [
            'email.unique' => 'Email sudah terdaftar. Akun yang ada tidak akan diubah.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Refuse visible input on terminals that cannot hide passwords.
        $credentials = [
            'password' => $this->secret('Password (minimal 12 karakter)', false),
            'password_confirmation' => $this->secret('Ulangi password', false),
        ];

        $validator = Validator::make($credentials, [
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            // User's hashed cast stores only a password hash.
            User::create([...$identity, 'password' => $credentials['password'], 'role' => 'admin']);
        } catch (UniqueConstraintViolationException) {
            $this->error('Email sudah terdaftar. Akun yang ada tidak akan diubah.');

            return self::FAILURE;
        }

        $this->info('Administrator berhasil dibuat. Masuk melalui /login; kelola akun analyst di Settings.');

        return self::SUCCESS;
    }
}
