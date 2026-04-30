<?php

namespace App\Services\Integrations\Connectors;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\SourceConnection;
use App\Models\SourceMapping;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Services\Alerts\ProjectLimitMonitorService;
use App\Services\Integrations\Contracts\SourceConnector;
use App\Services\Integrations\SyncResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;
use Weeek\Client as WeeekClient;
use Weeek\Exceptions\ApiErrorException;
use Weeek\Exceptions\TransportException;
use Weeek\Exceptions\UnexpectedResponseException;

class WeeekConnector
{
    public function supports(string $driver): bool
    {
        return in_array($driver, ['weeek', 'weeek.com'], true);
    }

    public function sync(SourceConnection $connection)
    {
        $settings = $this->resolveSettings($connection);

        if (! filled($settings['token'] ?? null)) {
            return SyncResult::fail('Weeek is not configured: WEEEK_TOKEN is required');
        }

        return $this->syncWithSettings($connection, $settings);
    }

    /**
     * @throws ApiErrorException
     * @throws ClientExceptionInterface
     * @throws UnexpectedResponseException
     * @throws TransportException
     */
    protected function syncWithSettings(SourceConnection $connection, array $settings)
    {
        $weeekApi = $this->buildWeeekClient($settings);
        $memberNames = [];
        $memberEmails = [];

        if ($weeekApi !== null) {
            foreach (($weeekApi->workspace->members()->members ?? []) as $member) {
                $memberId = (string) data_get($member, 'id', '');
                $memberName = trim((string) data_get($member, 'name', data_get($member, 'fullName', data_get($member, 'full_name', ''))));
                $memberEmail = trim((string) data_get($member, 'email', ''));

                if ($memberId !== '' && $memberName !== '') {
                    $memberNames[$memberId] = $memberName;
                }

                if ($memberId !== '' && $memberEmail !== '') {
                    $memberEmails[$memberId] = $memberEmail;
                }
            }
        }

        $projects = $this->normalizeWeeekList($weeekApi->taskManager->projects->getAll()->projects ?? []);

        foreach ($projects as $project) {

            Project::query()->updateOrCreate(
                [
                    'external_id' => $project['id']
                ],
                [
                    'name' => $project['name'],
                    'metadata' => $project,
                ]
            );
        }

        $projects = Project::query()
            ->where('status', 'active')
            ->get();

        foreach ($projects as $project) {
            foreach ($this->fetchAllProjectTasks($weeekApi, (int) $project->external_id) as $task) {

                $taskModel = Task::query()
                    ->updateOrCreate([
                        'external_id' => $task->id,
                    ], [
                        'project_id' => $project->id,
//                        'client_id' => $projectId ? Project::query()->find($projectId)?->client_id : $task->client_id,
//                        'assignee_employee_id' => $task->assignee_employee_id,
//                        'source_system' => 'Weeek',
//                        'external_id' => $externalId,
                        'title' => $task->title,
//                        'type' => data_get($payload, 'type', 'task'),
                        'status' => $task->isCompleted,
//                        'priority' => $this->taskPriority($payload),
//                        'due_at' => $this->toDateTime($task->dateStart),
                        'started_at' => $this->toDateTime($task->dateStart),
                        'completed_at' => $this->toDateTime($task->dateEnd),
//                        'estimate_hours' => $this->toFloat(data_get($payload, 'estimateHours', data_get($payload, 'estimate_hours'))),
//                        'spent_hours' => $this->taskSpentHours($payload),
//                        'is_blocked' => (bool) data_get($payload, 'isBlocked', data_get($payload, 'blocked', false)),
//                        'last_activity_at' => $this->toDateTime(data_get($payload, 'updatedAt', data_get($payload, 'updated_at'))),
                        'metadata' => array_merge($task->metadata ?? [], [
//                        'weeek_task' => $task->t,
//                        'weeek_time_entries' => $this->taskTimeEntries($payload),
                        ]),
                    ]);

                if (count($task->workloads) > 0) {

                    foreach ($task->workloads as $taskWorkload) {
                        $externalUserId = (string) $taskWorkload->userId;
                        $employeeName = $this->resolveEmployeeName((array) $task, (array) $taskWorkload, $externalUserId, $memberNames);
                        $memberEmail = $memberEmails[$externalUserId] ?? null;
                        $employeeId = $this->resolveEmployeeId($connection, $externalUserId, $task, $employeeName, $memberEmail);

                        TaskTimeEntry::query()
                            ->updateOrCreate([
                                'external_id' => $taskWorkload->id
                            ], [
                                'task_id' => $taskModel->id,
                                'employee_id' => $employeeId,
                                'entry_date' => $taskWorkload->date,
                                'minutes' => $taskWorkload->duration,
                                'metadata' => [
                                    'weeek' => [
                                        'user_id' => $externalUserId,
                                        'workload' => $taskWorkload,
                                    ],
                                ],
                            ]);
                    }
                }
            }
        }

        app(ProjectLimitMonitorService::class)->refresh();
    }

    /**
     * @return array<int, mixed>
     * @throws ApiErrorException
     * @throws ClientExceptionInterface
     * @throws UnexpectedResponseException
     * @throws TransportException
     */
    protected function fetchAllProjectTasks(WeeekClient $weeekApi, int $projectId): array
    {
        $tasks = [];
        $offset = 0;
        $perPage = 100;

        do {
            $response = $weeekApi->taskManager->tasks->getAll([
                'projectId' => $projectId,
                'perPage' => $perPage,
                'offset' => $offset,
            ]);

            $batch = $response->tasks ?? [];
            $tasks = array_merge($tasks, $batch);
            $offset += count($batch);
        } while (($response->hasMore ?? false) && count($batch) > 0);

        return $tasks;
    }

    protected function buildWeeekClient(array $settings): ?WeeekClient
    {
        $token = trim((string) ($settings['token'] ?? ''));
        $baseUrl = $this->normalizedBaseUrl((string) ($settings['base_url'] ?? ''));

        if ($token === '') {
            return null;
        }

        return new WeeekClient($token, $baseUrl !== '' ? $baseUrl : null);
    }

    protected function normalizedBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if ($baseUrl === '') {
            return '';
        }

        return str_contains($baseUrl, '/public/v1') ? $baseUrl : $baseUrl.'/public/v1';
    }

    protected function resolveSettings(SourceConnection $connection): array
    {
        return array_merge(
            config('services.weeek') ?? [],
            $this->filledSettings(is_array($connection->settings ?? null) ? $connection->settings : [])
        );
    }

    protected function filledSettings(array $settings): array
    {
        return array_filter($settings, fn ($value): bool => filled($value));
    }

    protected function syncTaskTimeEntries(SourceConnection $connection, Task $task, array $payload, array $memberNames = []): void
    {
        $entries = $this->taskTimeEntries($payload);
        $hourRate = (float) config('dashboard.production_hour_rate', 3000);

        foreach ($entries as $entry) {
            $externalId = (string) data_get($entry, 'id', '');

            if ($externalId === '') {
                continue;
            }

            $minutes = $this->taskEntryMinutes($entry);
            $externalUserId = (string) data_get($entry, 'userId', '');
            $employeeName = $this->resolveEmployeeName($payload, $entry, $externalUserId, $memberNames);
            $employeeId = $this->resolveEmployeeId($connection, $externalUserId, $task, $employeeName);

            TaskTimeEntry::query()->updateOrCreate(
                [
                    'external_id' => $externalId,
                ],
                [
                    'task_id' => $task->id,
                    'employee_id' => $employeeId,
                    'entry_date' => $this->toDate(data_get($entry, 'date'))?->toDateString() ?? now()->toDateString(),
                    'minutes' => $minutes,
                    'cost' => round(($minutes / 60) * $hourRate, 2),
                    'metadata' => [
                        'weeek' => $entry,
                    ],
                ]
            );
        }

        $task->spent_hours = TaskTimeEntry::query()
            ->where('task_id', $task->id)
            ->sum('minutes') / 60.0;
        $task->save();
    }

    protected function taskEntryMinutes(array $entry): int
    {
        $durationSeconds = data_get($entry, 'durationSeconds');

        if (is_numeric($durationSeconds)) {
            return (int) round(((float) $durationSeconds) / 60);
        }

        $duration = data_get($entry, 'duration');

        if (is_numeric($duration)) {
            return (int) round(((float) $duration));
        }

        return 0;
    }

    protected function resolveEmployeeId(SourceConnection $connection, string $externalUserId, object $task, ?string $displayName = null, ?string $memberEmail = null): ?int
    {
        if ($externalUserId === '') {
            return null;
        }

        $employee = Employee::query()
            ->where('weeek_uuid', $externalUserId)
            ->first();

        if ($employee) {
            if (filled($displayName) && ! filled($employee->name)) {
                $employee->name = $displayName;
                $employee->save();
            }

            SourceMapping::query()->updateOrCreate(
                [
                    'source_key' => $connection->source_key,
                    'external_type' => 'user',
                    'external_id' => $externalUserId,
                ],
                [
                    'source_connection_id' => $connection->id,
                    'internal_type' => Employee::class,
                    'internal_id' => $employee->id,
                    'label' => $employee->name,
                    'is_primary' => true,
                    'metadata' => ['weeek' => ['id' => $externalUserId]],
                ]
            );

            return $employee->id;
        }

        $candidateQuery = Employee::query();

        if (filled($memberEmail)) {
            $candidateQuery->orWhereRaw('lower(email) = ?', [Str::lower(trim($memberEmail))]);
        }

        if (filled($displayName)) {
            $candidateQuery->orWhereRaw('lower(trim(name)) = ?', [Str::lower(trim($displayName))]);
        }

        $existingEmployee = $candidateQuery->first();

        if ($existingEmployee) {
            if (! filled($existingEmployee->weeek_uuid)) {
                $existingEmployee->weeek_uuid = $externalUserId;
                $existingEmployee->save();
            }

            SourceMapping::query()->updateOrCreate(
                [
                    'source_key' => $connection->source_key,
                    'external_type' => 'user',
                    'external_id' => $externalUserId,
                ],
                [
                    'source_connection_id' => $connection->id,
                    'internal_type' => Employee::class,
                    'internal_id' => $existingEmployee->id,
                    'label' => $existingEmployee->name,
                    'is_primary' => true,
                    'metadata' => ['weeek' => ['id' => $externalUserId]],
                ]
            );

            return $existingEmployee->id;
        }

        $mapping = SourceMapping::query()
            ->where('source_key', $connection->source_key)
            ->where('external_type', 'user')
            ->where('external_id', $externalUserId)
            ->first();

        if ($mapping?->internal_type === Employee::class) {
                $employee = Employee::query()->find((int) $mapping->internal_id);

                if ($employee) {
                    if (! filled($employee->weeek_uuid)) {
                        $employee->weeek_uuid = $externalUserId;
                    }

                    if (filled($displayName) && Str::of((string) $employee->name)->lower()->contains('weeek user')) {
                        $employee->name = $displayName;
                        $employee->save();
                        $mapping->label = $displayName;
                        $mapping->save();
                    } elseif ($employee->isDirty('weeek_uuid')) {
                        $employee->save();
                    }

                    return $employee->id;
                }

                if (filled($displayName)) {
                    $existingEmployee = Employee::query()
                        ->whereRaw('lower(trim(name)) = ?', [Str::lower(trim($displayName))])
                        ->first();

                    if ($existingEmployee) {
                        if (! filled($existingEmployee->weeek_uuid)) {
                            $existingEmployee->weeek_uuid = $externalUserId;
                            $existingEmployee->save();
                        }

                        $mapping->internal_id = $existingEmployee->id;
                        $mapping->label = $existingEmployee->name;
                        $mapping->save();

                        return $existingEmployee->id;
                    }
                }

                if (filled($displayName) || filled($memberEmail)) {
                    $employee = Employee::query()->firstOrCreate(
                        [
                            'email' => filled($memberEmail) ? $memberEmail : 'weeek-'.$externalUserId.'@example.com',
                        ],
                        [
                            'name' => $displayName ?? $existingEmployee?->name ?? 'Weeek user '.$externalUserId,
                            'weeek_uuid' => $externalUserId,
                            'role_title' => 'Production',
                            'is_active' => true,
                            'capacity_hours_per_week' => 40,
                            'weekly_limit_hours' => 40,
                            'metadata' => [
                                'weeek_user_id' => $externalUserId,
                            ],
                        ]
                    );

                    if (! filled($employee->weeek_uuid)) {
                        $employee->weeek_uuid = $externalUserId;
                        $employee->save();
                    }

                    $mapping->internal_id = $employee->id;
                    $mapping->label = $employee->name;
                    $mapping->save();

                    return $employee->id;
                }

                return null;
            }

        if (filled($displayName)) {
            $employee = Employee::query()
                ->whereRaw('lower(trim(name)) = ?', [Str::lower(trim($displayName))])
                ->first();

            if ($employee) {
                if (! filled($employee->weeek_uuid)) {
                    $employee->weeek_uuid = $externalUserId;
                    $employee->save();
                }

                SourceMapping::query()->updateOrCreate(
                    [
                        'source_key' => $connection->source_key,
                        'external_type' => 'user',
                        'external_id' => $externalUserId,
                    ],
                    [
                        'source_connection_id' => $connection->id,
                        'internal_type' => Employee::class,
                        'internal_id' => $employee->id,
                        'label' => $employee->name,
                        'is_primary' => true,
                        'metadata' => ['weeek' => ['id' => $externalUserId]],
                    ]
                );

                return $employee->id;
            }
        }

        $name = filled($displayName) ? $displayName : 'Weeek user '.$externalUserId;

        $employee = Employee::query()->firstOrCreate(
            [
                'email' => 'weeek-'.$externalUserId.'@example.com',
            ],
            [
                'name' => $name,
                'weeek_uuid' => $externalUserId,
                'role_title' => 'Production',
                'is_active' => true,
                'capacity_hours_per_week' => 40,
                'weekly_limit_hours' => 40,
                'metadata' => [
                    'weeek_user_id' => $externalUserId,
                ],
            ]
        );

        if (! filled($employee->weeek_uuid)) {
            $employee->weeek_uuid = $externalUserId;
            $employee->save();
        }

        SourceMapping::query()->updateOrCreate(
            [
                'source_key' => $connection->source_key,
                'external_type' => 'user',
                'external_id' => $externalUserId,
            ],
            [
                'source_connection_id' => $connection->id,
                'internal_type' => Employee::class,
                'internal_id' => $employee->id,
                'label' => $employee->name,
                'is_primary' => true,
                'metadata' => ['weeek' => ['id' => $externalUserId]],
            ]
        );

        return $employee->id;
    }

    protected function resolveEmployeeName(array $taskPayload, array $entry, string $externalUserId, array $memberNames = []): ?string
    {
        $candidates = [
            $memberNames[$externalUserId] ?? null,
            data_get($entry, 'userName'),
            data_get($entry, 'user.name'),
            data_get($entry, 'user.fullName'),
            data_get($entry, 'user.full_name'),
            data_get($entry, 'assignee.name'),
            data_get($entry, 'assignee.fullName'),
            data_get($entry, 'assignee.full_name'),
            data_get($entry, 'member.name'),
            data_get($entry, 'member.fullName'),
            data_get($taskPayload, 'assignee.name'),
            data_get($taskPayload, 'assignee.fullName'),
            data_get($taskPayload, 'assignee.full_name'),
            data_get($taskPayload, 'assignees.0.name'),
            data_get($taskPayload, 'assignees.0.fullName'),
            data_get($taskPayload, 'assignees.0.full_name'),
            data_get($taskPayload, 'members.0.name'),
            data_get($taskPayload, 'members.0.fullName'),
            data_get($taskPayload, 'members.0.full_name'),
        ];

        foreach ($candidates as $candidate) {
            $name = trim((string) $candidate);

            if ($name !== '') {
                return $name;
            }
        }

        return filled($externalUserId) ? null : null;
    }

    protected function resolveProjectId(SourceConnection $connection, array $payload): ?int
    {
        $externalProjectId = $this->taskProjectExternalId($payload);

        if (! $externalProjectId) {
            $locations = data_get($payload, 'locations', []);

            if (is_array($locations) && count($locations) > 0) {
                $externalProjectId = data_get($locations, '0.projectId', data_get($locations, '0.project_id', data_get($locations, '0.id')));
            }
        }

        if (! $externalProjectId) {
            return null;
        }

        $mapping = SourceMapping::query()
            ->where('source_key', $connection->source_key)
            ->where('external_type', 'project')
            ->where('external_id', (string) $externalProjectId)
            ->first();

        if (! $mapping || $mapping->internal_type !== Project::class) {
            return null;
        }

        return (int) $mapping->internal_id;
    }

    protected function shouldSkipProject(array $payload, array $excludedProjectIds, bool $syncArchivedProjects): bool
    {
        $externalId = (string) data_get($payload, 'id', data_get($payload, 'projectId', data_get($payload, 'project_id')));

        if ($externalId !== '' && in_array($externalId, $excludedProjectIds, true)) {
            return true;
        }

        if ($syncArchivedProjects) {
            return false;
        }

        $status = Str::lower((string) data_get($payload, 'status', data_get($payload, 'state', '')));
        $archived = (bool) data_get($payload, 'archived', false);

        return $archived || Str::contains($status, ['archive', 'closed', 'done']);
    }

    protected function taskProjectExternalId(array $payload): ?string
    {
        $externalProjectId = data_get($payload, 'projectId', data_get($payload, 'project_id'));

        if ($externalProjectId) {
            return (string) $externalProjectId;
        }

        $locations = data_get($payload, 'locations', []);

        if (is_array($locations) && count($locations) > 0) {
            $candidate = data_get($locations, '0.projectId', data_get($locations, '0.project_id', data_get($locations, '0.id')));

            if ($candidate) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $projectCatalog
     * @param array<int, string> $excludedProjectIds
     * @return array<int, string>
     */
    protected function skippedProjectIds(array $projectCatalog, array $excludedProjectIds, bool $syncArchivedProjects): array
    {
        $ids = [];

        foreach ($projectCatalog as $externalId => $payload) {
            if ($this->shouldSkipProject($payload, $excludedProjectIds, $syncArchivedProjects)) {
                $ids[] = (string) $externalId;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    protected function ensureMapping(SourceConnection $connection, string $externalType, string $externalId, string $internalType, int $internalId, string $label): void
    {
        SourceMapping::query()->updateOrCreate(
            [
                'source_key' => $connection->source_key,
                'external_type' => $externalType,
                'external_id' => $externalId,
            ],
            [
                'source_connection_id' => $connection->id,
                'internal_type' => $internalType,
                'internal_id' => $internalId,
                'label' => $label,
                'is_primary' => true,
                'metadata' => [
                    'weeek' => [
                        'id' => $externalId,
                        'type' => $externalType,
                    ],
                ],
            ]
        );
    }

    protected function modelByMapping(SourceConnection $connection, string $sourceKey, string $externalType, string $externalId): ?object
    {
        $mapping = SourceMapping::query()
            ->where('source_key', $sourceKey)
            ->where('external_type', $externalType)
            ->where('external_id', $externalId)
            ->first();

        if (! $mapping) {
            return null;
        }

        return match ($mapping->internal_type) {
            Project::class => Project::query()->find($mapping->internal_id),
            Task::class => Task::query()->find($mapping->internal_id),
            Client::class => Client::query()->find($mapping->internal_id),
            default => null,
        };
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeWeeekList(array $items): array
    {
        return array_values(array_filter(array_map(function ($item): array {
            if (is_array($item)) {
                return $item;
            }

            if (is_object($item)) {
                $payload = get_object_vars($item);

                return is_array($payload) ? $payload : [];
            }

            return [];
        }, $items)));
    }

    protected function projectName(array $payload): string
    {
        $name = trim((string) data_get($payload, 'name', ''));

        return $name !== '' ? $name : 'Weeek project #'.data_get($payload, 'id');
    }

    protected function taskName(array $payload): string
    {
        $name = trim((string) data_get($payload, 'title', data_get($payload, 'name', '')));

        return $name !== '' ? $name : 'Weeek task #'.data_get($payload, 'id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function taskTimeEntries(array $payload): array
    {
        $entries = [];

        foreach (['timeEntries', 'workloads'] as $key) {
            $candidate = data_get($payload, $key, []);

            if (! is_array($candidate)) {
                continue;
            }

            foreach ($candidate as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entries[(string) data_get($entry, 'id', md5(json_encode($entry)))] = $entry;
            }
        }

        return array_values($entries);
    }

    protected function taskSpentHours(array $payload): float
    {
        $entries = $this->taskTimeEntries($payload);

        if (! empty($entries)) {
            $seconds = collect($entries)->sum(function (array $entry): float {
                $durationSeconds = data_get($entry, 'durationSeconds');

                if (is_numeric($durationSeconds)) {
                    return (float) $durationSeconds;
                }

                $duration = data_get($entry, 'duration');

                if (is_numeric($duration)) {
                    return (float) $duration * 60;
                }

                return 0.0;
            });

            return round($seconds / 3600, 2);
        }

        return $this->toFloat(data_get($payload, 'spentHours', data_get($payload, 'spent_hours', data_get($payload, 'timeSpentHours'))));
    }

    protected function projectStatus(array $payload): string
    {
        if ((bool) data_get($payload, 'archived', false) || Str::contains(Str::lower((string) data_get($payload, 'status', '')), ['archive', 'closed'])) {
            return 'done';
        }

        if (Str::contains(Str::lower((string) data_get($payload, 'status', '')), ['pause', 'hold'])) {
            return 'paused';
        }

        return 'active';
    }

    protected function projectHealth(array $payload): string
    {
        if ((bool) data_get($payload, 'archived', false)) {
            return 'green';
        }

        $spent = $this->toFloat(data_get($payload, 'spentHours', data_get($payload, 'spent_hours')));
        $planned = $this->toFloat(data_get($payload, 'plannedHours', data_get($payload, 'estimateHours')));
        $updatedAt = $this->toDateTime(data_get($payload, 'updatedAt', data_get($payload, 'updated_at')));

        if ($spent > $planned && $planned > 0) {
            return 'red';
        }

        if ($updatedAt && $updatedAt->diffInDays(now()) > 10) {
            return 'yellow';
        }

        return 'green';
    }

    protected function taskStatus(array $payload): string
    {
        $status = Str::lower((string) data_get($payload, 'status', data_get($payload, 'state', 'open')));

        return match (true) {
            Str::contains($status, ['done', 'complete', 'closed']) => 'done',
            Str::contains($status, ['progress', 'work']) => 'in_progress',
            Str::contains($status, ['blocked', 'pause', 'hold']) => 'blocked',
            default => 'open',
        };
    }

    protected function taskPriority(array $payload): string
    {
        $priority = Str::lower((string) data_get($payload, 'priority', 'normal'));

        return match (true) {
            Str::contains($priority, ['high', 'urgent', 'critical']) => 'high',
            Str::contains($priority, ['low']) => 'low',
            default => 'normal',
        };
    }

    protected function riskScore(array $payload): float
    {
        $spent = $this->toFloat(data_get($payload, 'spentHours', data_get($payload, 'spent_hours')));
        $planned = $this->toFloat(data_get($payload, 'plannedHours', data_get($payload, 'estimateHours')));

        if ($planned <= 0) {
            return 0.1;
        }

        return round(min(1, max(0.05, $spent / max(1, $planned))), 4);
    }

    protected function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function toDate(mixed $value): ?CarbonImmutable
    {
        $date = $this->toDateTime($value);

        return $date?->startOfDay();
    }

    protected function toDateTime(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
