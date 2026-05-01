<?php

namespace App\Support;

class FinanceTransactionTypes
{
    public const UNCLASSIFIED = 'Не классифицировано';

    public const INCOME = [
        'Внедрение',
        'Перевнедрение',
        'Сопровождение',
        'Разработка',
        'Аудит',
        'Лицензии',
        'Виджеты',
        'Консультация',
        'Пакет часов',
        'Другое поступление',
    ];

    public const EXPENSE = [
        'ФОТ',
        'Подрядчики',
        'Сервисы',
        'Реклама / маркетинг',
        'Налоги',
        'Офис / аренда',
        'Банковские комиссии',
        'Возвраты',
        'Личные выплаты собственнику',
        'Другое расход',
    ];

    public const RECURRING_INCOME = [
        'Сопровождение',
        'Лицензии',
    ];

    public static function allOptions(): array
    {
        return ['' => self::UNCLASSIFIED]
            + array_combine(self::INCOME, self::INCOME)
            + array_combine(self::EXPENSE, self::EXPENSE);
    }

    public static function normalize(?string $type): ?string
    {
        $type = trim((string) $type);

        return $type !== '' ? $type : null;
    }

    public static function label(?string $type): string
    {
        return self::normalize($type) ?? self::UNCLASSIFIED;
    }

    public static function isRecurringIncome(?string $type): bool
    {
        return in_array(self::normalize($type), self::RECURRING_INCOME, true);
    }
}
