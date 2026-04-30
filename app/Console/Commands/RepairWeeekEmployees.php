<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\SourceConnection;
use App\Models\SourceMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class RepairWeeekEmployees extends Command
{
    protected $signature = 'weeek:repair-employees';

    protected $description = 'Fetch Weeek users and repair employee names/uuid mappings.';

    public function handle(): int
    {
        $connection = SourceConnection::query()
            ->where('source_key', 'weeek')
            ->first();

        $settings = array_merge(
            config('services.weeek') ?? [],
            array_filter(is_array($connection?->settings ?? null) ? $connection->settings : [], fn ($value): bool => filled($value)),
        );

        $token = trim((string) ($settings['token'] ?? ''));

        if ($token === '') {
            $this->error('WEEEK_TOKEN is empty.');

            return self::FAILURE;
        }

        $members = $this->fetchMembers($settings, $token);
        $fixed = 0;

        foreach ($members as $member) {
            $externalId = trim((string) (data_get($member, 'id') ?? data_get($member, 'userId') ?? data_get($member, 'user_id') ?? ''));
            $name = $this->memberName($member);
            $email = trim((string) data_get($member, 'email', ''));

            if ($externalId === '' || $name === '') {
                continue;
            }

            $employee = Employee::query()
                ->where('weeek_uuid', $externalId)
                ->when($email !== '', fn ($query) => $query->orWhereRaw('lower(email) = ?', [Str::lower($email)]))
                ->orWhereRaw('lower(trim(name)) = ?', [Str::lower($name)])
                ->first();

            if (! $employee) {
                $employee = Employee::query()->create($this->employeeAttributes([
                    'name' => $name,
                    'email' => $email !== '' ? $email : 'weeek-'.$externalId.'@example.com',
                    'weeek_uuid' => $externalId,
                    'role_title' => 'Production',
                    'is_active' => true,
                    'capacity_hours_per_week' => 40,
                    'weekly_limit_hours' => 40,
                    'metadata' => ['weeek_user' => $member],
                ]));
            }

            $employee->weeek_uuid = $externalId;

            if ($this->shouldReplaceName((string) $employee->name)) {
                $employee->name = $name;
            }

            if ($email !== '' && blank($employee->email)) {
                $employee->email = $email;
            }

            $employee->metadata = array_merge($employee->metadata ?? [], ['weeek_user' => $member]);
            $employee->save();

            SourceMapping::query()->updateOrCreate(
                [
                    'source_key' => 'weeek',
                    'external_type' => 'user',
                    'external_id' => $externalId,
                ],
                [
                    'source_connection_id' => $connection?->id,
                    'internal_type' => Employee::class,
                    'internal_id' => $employee->id,
                    'label' => $employee->name,
                    'is_primary' => true,
                    'metadata' => ['weeek_user' => $member],
                ],
            );

            $fixed++;
        }

        $this->backfillFromExistingMappings();

        $unresolved = DB::table('task_time_entries')
            ->leftJoin('employees', function ($join) {
                $join->whereRaw('employees.weeek_uuid::text = task_time_entries.employee_id::text');
            })
            ->whereNull('employees.id')
            ->whereNotNull('task_time_entries.employee_id')
            ->distinct()
            ->pluck('task_time_entries.employee_id')
            ->take(20)
            ->all();

        $this->info("Repaired Weeek employees: {$fixed}");

        if ($unresolved !== []) {
            $this->warn('Still unresolved Weeek UUIDs: '.implode(', ', $unresolved));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchMembers(array $settings, string $token): array
    {
        $baseUrl = $this->normalizedBaseUrl((string) ($settings['base_url'] ?? 'https://api.weeek.net'));

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout((int) ($settings['connect_timeout'] ?? 10))
                ->timeout((int) ($settings['timeout'] ?? 30))
                ->retry(3, 1000)
                ->get($baseUrl.'/ws/members');

            $response->throw();
        } catch (Throwable $throwable) {
            $this->error('Cannot fetch Weeek members: '.$throwable->getMessage());

            return [];
        }

        $payload = $response->json() ?? [];
        $members = data_get($payload, 'members')
            ?? data_get($payload, 'data.members')
            ?? data_get($payload, '_embedded.members')
            ?? [];

        return is_array($members) ? array_values(array_filter($members, fn ($member): bool => is_array($member))) : [];
    }

    protected function normalizedBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if ($baseUrl === '') {
            return 'https://api.weeek.net/public/v1';
        }

        return str_contains($baseUrl, '/public/v1') ? $baseUrl : $baseUrl.'/public/v1';
    }

    protected function backfillFromExistingMappings(): void
    {
        DB::statement(<<<'SQL'
            update employees
            set weeek_uuid = source_mappings.external_id::uuid
            from source_mappings
            where source_mappings.source_key = 'weeek'
                and source_mappings.external_type = 'user'
                and source_mappings.internal_type = 'App\Models\Employee'
                and source_mappings.internal_id = employees.id
                and employees.weeek_uuid is null
                and source_mappings.external_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
        SQL);

        DB::statement(<<<'SQL'
            update employees
            set name = source_mappings.label
            from source_mappings
            where source_mappings.source_key = 'weeek'
                and source_mappings.external_type = 'user'
                and source_mappings.internal_type = 'App\Models\Employee'
                and source_mappings.internal_id = employees.id
                and source_mappings.label is not null
                and trim(source_mappings.label) <> ''
                and lower(trim(source_mappings.label)) not like 'weeek user%'
                and source_mappings.label !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                and (
                    employees.name is null
                    or trim(employees.name) = ''
                    or lower(employees.name) like 'weeek user%'
                    or employees.name ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                )
        SQL);
    }

    protected function shouldReplaceName(string $name): bool
    {
        $name = trim($name);

        return $name === ''
            || Str::of($name)->lower()->startsWith('weeek user')
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name) === 1;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function employeeAttributes(array $attributes): array
    {
        return array_filter(
            $attributes,
            fn (mixed $value, string $column): bool => Schema::hasColumn('employees', $column),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param array<string, mixed> $member
     */
    protected function memberName(array $member): string
    {
        $candidates = [
            data_get($member, 'name'),
            data_get($member, 'fullName'),
            data_get($member, 'full_name'),
            data_get($member, 'displayName'),
            data_get($member, 'display_name'),
            trim(implode(' ', array_filter([
                data_get($member, 'firstName'),
                data_get($member, 'middleName'),
                data_get($member, 'lastName'),
            ], fn ($part): bool => filled($part)))),
            trim(implode(' ', array_filter([
                data_get($member, 'first_name'),
                data_get($member, 'middle_name'),
                data_get($member, 'last_name'),
            ], fn ($part): bool => filled($part)))),
            data_get($member, 'email'),
        ];

        foreach ($candidates as $candidate) {
            $name = trim((string) $candidate);

            if ($name !== '' && ! $this->shouldReplaceName($name)) {
                return $name;
            }
        }

        return '';
    }
}
