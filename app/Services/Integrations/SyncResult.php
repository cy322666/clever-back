<?php

namespace App\Services\Integrations;

class SyncResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $pulled = 0,
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $errors = 0,
        public readonly ?string $message = null,
        public readonly array $payload = [],
    ) {}

    public static function ok(int $pulled = 0, int $created = 0, int $updated = 0, array $payload = [], ?string $message = null): self
    {
        return new self(true, $pulled, $created, $updated, 0, $message, $payload);
    }

    public static function fail(string $message, int $errors = 1, array $payload = []): self
    {
        return new self(false, 0, 0, 0, $errors, $message, $payload);
    }
}
