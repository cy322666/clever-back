<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class OwnerBootstrapper
{
    public function ensureOwnerUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => config('auth.owner_email', 'owner@example.com')],
            [
                'name' => config('auth.owner_name', 'Owner'),
                'password' => Hash::make((string) config('auth.owner_password', 'password')),
            ]
        );
    }
}
