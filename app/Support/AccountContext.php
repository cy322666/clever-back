<?php

namespace App\Support;

final class AccountContext
{
    public function __construct(
        public readonly string $name = 'Owner Analytics',
        public readonly string $timezone = 'Europe/Kaliningrad',
        public readonly array $settings = [],
    ) {}
}
