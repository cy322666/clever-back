<?php

namespace App\Services\Integrations;

use App\Models\SourceConnection;
use Illuminate\Support\Collection;

class SourceConnectionBootstrapper
{
    /**
     * @return Collection<int, SourceConnection>
     */
    public function ensureDefaults(): Collection
    {
        $sources = collect(config('integrations.sources', []));

        return $sources->map(function (array $source, string $key) {
            $existing = SourceConnection::query()
                ->where('source_key', $key)
                ->first();

            $existingSettings = is_array($existing?->settings ?? null) ? $existing->settings : [];
            $defaultSettings = is_array(config("services.{$key}") ?? null) ? config("services.{$key}") : [];

            return SourceConnection::query()->updateOrCreate(
                [
                    'source_key' => $key,
                ],
                [
                    'name' => $source['name'] ?? $key,
                    'driver' => $source['driver'] ?? $key,
                    'status' => (($source['enabled'] ?? true) ? 'inactive' : 'disabled'),
                    'is_enabled' => (bool) ($source['enabled'] ?? true),
                    'settings' => array_merge($defaultSettings, $existingSettings, [
                        'bootstrap' => true,
                    ]),
                ]
            );
        });
    }
}
