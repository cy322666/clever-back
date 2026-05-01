<?php

return [
    'periods' => [
        'today' => 'Сегодня',
        '7d' => '7 дней',
        '30d' => '30 дней',
        'month' => 'Текущий месяц',
        'prev-month' => 'Прошлый месяц',
        'quarter' => 'Квартал',
        'custom' => 'Свой диапазон',
    ],
    'thresholds' => [
        'project_idle_days' => (int) env('ALERT_PROJECT_IDLE_DAYS', 5),
        'deal_idle_days' => (int) env('ALERT_DEAL_IDLE_DAYS', 4),
        'support_overage_threshold' => (float) env('ALERT_SUPPORT_OVERAGE_THRESHOLD', 1.0),
        'high_utilization_threshold' => (float) env('ALERT_HIGH_UTILIZATION_THRESHOLD', 0.85),
        'low_utilization_threshold' => (float) env('ALERT_LOW_UTILIZATION_THRESHOLD', 0.35),
        'low_margin_threshold' => (float) env('ALERT_LOW_MARGIN_THRESHOLD', 0.12),
        'client_vip_revenue_threshold' => (float) env('CLIENT_VIP_REVENUE_THRESHOLD', 1500000),
        'client_growth_revenue_threshold' => (float) env('CLIENT_GROWTH_REVENUE_THRESHOLD', 350000),
        'client_risk_idle_days' => (int) env('CLIENT_RISK_IDLE_DAYS', 21),
        'client_watch_idle_days' => (int) env('CLIENT_WATCH_IDLE_DAYS', 14),
    ],
    'production_hour_rate' => (float) env('PRODUCTION_HOUR_RATE', 3000),
    'owner_user_id' => env('OWNER_USER_ID'),
    'owner_email' => env('OWNER_EMAIL'),
    'primary_sales_pipeline_name' => trim((string) env('AMO_PRIMARY_PIPELINE_NAME', 'Основная')),
    'repeat_sales_pipeline_name' => trim((string) env('AMO_REPEAT_PIPELINE_NAME', 'Повторные')),
    'support_pipeline_name' => trim((string) env('AMO_SUPPORT_PIPELINE_NAME', 'Сопровождение')),
    'amo_allowed_pipeline_names' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AMO_ALLOWED_PIPELINE_NAMES', 'Основная,Повторные,Виджеты,Сопровождение'))
    ))),
    'amo_excluded_pipeline_names' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AMO_EXCLUDED_PIPELINE_NAMES', ''))
    ))),
];
