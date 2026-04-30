<?php

namespace App\Console\Commands;

use App\Services\Alerts\ProjectLimitMonitorService;
use App\Services\Notifications\TelegramNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendProjectLoadTelegramReport extends Command
{
    protected $signature = 'projects:load-telegram {--force : Send even if today report was already sent}';

    protected $description = 'Send a Telegram report about project load thresholds.';

    public function handle(ProjectLimitMonitorService $monitor, TelegramNotifier $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->warn('Telegram is not configured. Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID.');

            return self::SUCCESS;
        }

        $today = CarbonImmutable::now()->toDateString();
        $cacheKey = "project-load-telegram-report:{$today}";

        if (! $this->option('force') && Cache::has($cacheKey)) {
            $this->info('Project load Telegram report was already sent today.');

            return self::SUCCESS;
        }

        $rows = $monitor->projectRows()
            ->map(fn (array $row): array => array_merge($row, [
                'threshold' => $this->thresholdFor((float) $row['utilization_pct']),
            ]))
            ->filter(fn (array $row): bool => $row['threshold'] !== null)
            ->sortByDesc('utilization_pct')
            ->values();

        if ($rows->isEmpty()) {
            $this->info('No projects above 50% load.');

            Cache::put($cacheKey, true, CarbonImmutable::now()->endOfDay());

            return self::SUCCESS;
        }

        try {
            $telegram->send($this->message($rows->all()));
        } catch (Throwable $throwable) {
            $this->warn($throwable->getMessage());

            return self::SUCCESS;
        }

        Cache::put($cacheKey, true, CarbonImmutable::now()->endOfDay());
        $this->info("Project load Telegram report sent. Projects: {$rows->count()}.");

        return self::SUCCESS;
    }

    protected function thresholdFor(float $utilizationPct): ?int
    {
        return match (true) {
            $utilizationPct >= 100 => 100,
            $utilizationPct >= 90 => 90,
            $utilizationPct >= 70 => 70,
            $utilizationPct >= 50 => 50,
            default => null,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function message(array $rows): string
    {
        $date = CarbonImmutable::now()->format('d.m.Y');
        $lines = [
            '<b>Нагрузка проектов на '.$date.'</b>',
            'Пороги: 50 / 70 / 90 / 100%',
            '',
        ];

        foreach ($rows as $row) {
            $threshold = (int) $row['threshold'];
            $icon = match ($threshold) {
                100 => '🔴',
                90 => '🟠',
                70 => '🟡',
                default => '🟢',
            };
            $overrun = (float) $row['overrun_hours'] > 0
                ? ' · +'.number_format((float) $row['overrun_hours'], 1, ',', ' ').' ч сверх'
                : '';

            $lines[] = sprintf(
                '%s <b>%s</b>: %s%% · %s / %s ч · порог %s%%%s',
                $icon,
                e((string) $row['project_name']),
                number_format((float) $row['utilization_pct'], 1, ',', ' '),
                number_format((float) $row['spent_hours'], 1, ',', ' '),
                number_format((float) $row['planned_hours'], 1, ',', ' '),
                $threshold,
                $overrun,
            );
        }

        return implode("\n", $lines);
    }
}
