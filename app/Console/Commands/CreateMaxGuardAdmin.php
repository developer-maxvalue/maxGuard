<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final class CreateMaxGuardAdmin extends Command
{
    protected $signature = 'maxguard:create-admin
        {--name= : Administrator display name}
        {--email= : Administrator email address}
        {--password= : Password (omit this option to enter it securely)}';

    protected $description = 'Create the administrator account used to sign in to MaxGuard';

    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Administrator name', 'MaxGuard Administrator')));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Administrator email'))));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User();
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ])->save();

        $this->info("Administrator [{$email}] created. You can now sign in at /login.");

        return self::SUCCESS;
    }
}
