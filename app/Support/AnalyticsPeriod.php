<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class AnalyticsPeriod
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $key,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $period = (string) $request->string('period', '30d');
        $today = CarbonImmutable::now()->startOfDay();

        return match ($period) {
            'all' => new self(CarbonImmutable::parse('1970-01-01')->startOfDay(), $today->endOfDay(), $period),
            'today' => new self($today, $today->endOfDay(), $period),
            '7d' => new self($today->subDays(6)->startOfDay(), $today->endOfDay(), $period),
            '30d' => new self($today->subDays(29)->startOfDay(), $today->endOfDay(), $period),
            'month' => new self($today->startOfMonth(), $today->endOfMonth(), $period),
            'prev-month' => new self($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth(), $period),
            'quarter' => new self($today->startOfQuarter(), $today->endOfQuarter(), $period),
            'custom' => new self(
                CarbonImmutable::parse($request->string('from', $today->subDays(29)->toDateString()))->startOfDay(),
                CarbonImmutable::parse($request->string('to', $today->toDateString()))->endOfDay(),
                'custom'
            ),
            default => new self(
                CarbonImmutable::parse($request->string('from', $today->subDays(29)->toDateString()))->startOfDay(),
                CarbonImmutable::parse($request->string('to', $today->toDateString()))->endOfDay(),
                '30d'
            ),
        };
    }

    public static function preset(string $period): self
    {
        $today = CarbonImmutable::now()->startOfDay();

        return match ($period) {
            'all' => new self(CarbonImmutable::parse('1970-01-01')->startOfDay(), $today->endOfDay(), $period),
            'today' => new self($today, $today->endOfDay(), $period),
            '7d' => new self($today->subDays(6)->startOfDay(), $today->endOfDay(), $period),
            '30d' => new self($today->subDays(29)->startOfDay(), $today->endOfDay(), $period),
            'month' => new self($today->startOfMonth(), $today->endOfMonth(), $period),
            'prev-month' => new self($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth(), $period),
            'quarter' => new self($today->startOfQuarter(), $today->endOfQuarter(), $period),
            'custom' => new self($today->subDays(29)->startOfDay(), $today->endOfDay(), 'custom'),
            default => new self($today->subDays(29)->startOfDay(), $today->endOfDay(), '30d'),
        };
    }

    /**
     * @param  array{from?: string, to?: string, key?: string}  $payload
     */
    public static function fromArray(array $payload): self
    {
        $today = CarbonImmutable::now()->startOfDay();
        $key = (string) ($payload['key'] ?? '30d');

        if ($key === 'all') {
            return new self(CarbonImmutable::parse('1970-01-01')->startOfDay(), $today->endOfDay(), $key);
        }

        $from = isset($payload['from'])
            ? CarbonImmutable::parse((string) $payload['from'])->startOfDay()
            : $today->subDays(29)->startOfDay();
        $to = isset($payload['to'])
            ? CarbonImmutable::parse((string) $payload['to'])->endOfDay()
            : $today->endOfDay();

        return new self($from, $to, $key);
    }

    public function previousComparable(): self
    {
        $days = max(1, $this->from->diffInDays($this->to) + 1);
        $previousTo = $this->from->subDay()->endOfDay();
        $previousFrom = $previousTo->subDays($days - 1)->startOfDay();

        return new self($previousFrom, $previousTo, $this->key);
    }

    public function label(): string
    {
        return match ($this->key) {
            'all' => 'Всё время',
            'today' => 'Сегодня',
            '7d' => '7 дней',
            '30d' => '30 дней',
            'month' => 'Текущий месяц',
            'prev-month' => 'Прошлый месяц',
            'quarter' => 'Квартал',
            default => $this->from->toDateString().' - '.$this->to->toDateString(),
        };
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'key' => $this->key,
        ];
    }
}
