<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateOwner extends Command
{
    protected $signature = 'gateway:owner {email?}';

    protected $description = 'Create the single gateway owner (password is prompted securely).';

    public function handle(): int
    {
        if (User::exists()) {
            $this->error('An owner already exists.');

            return self::FAILURE;
        }
        $email = $this->argument('email') ?: $this->ask('Email');
        $password = $this->secret('Password (at least 12 characters)');
        $validator = Validator::make(compact('email', 'password'), ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        User::create(['name' => 'Owner', 'email' => $email, 'password' => $password]);
        $this->info('Owner created. Sign in at /app.');

        return self::SUCCESS;
    }
}
