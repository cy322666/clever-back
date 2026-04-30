<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class OwnerBootstrapper
{
    public function ensureOwnerUser(): ?User
    {
        if (! Schema::hasTable('users')) {
            return null;
        }

        return User::query()->firstOrCreate(
            ['email' => config('auth.owner_email', 'owner@example.com')],
            [
                'name' => config('auth.owner_name', 'Owner'),
                'password' => Hash::make((string) config('auth.owner_password', 'password')),
            ]
        );
    }
}
