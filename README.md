# Owner Analytics

Laravel 12 аналитическая панель для собственника интеграторской amoCRM-студии.

Это не CRM и не ERP. Приложение собирает управленческую картину по:
- продажам
- производству
- сопровождению
- деньгам
- рискам

## Архитектура

### Слои
1. Источники данных
   - amoCRM connector
   - Weeek connector
   - bank import connector
2. Нормализованная доменная модель
   - companies
   - clients
   - employees
   - sales leads / opportunities
   - projects / support contracts
   - tasks / time entries
   - revenue / expense / cashflow
   - alerts / metric snapshots / sync logs
3. Дашборды и аналитика
   - обзор собственника
   - продажи
   - производство
   - сопровождение
   - финансы
   - риски

### Основные сущности
- `Company`, `Client`, `Employee`, `Department`
- `Pipeline`, `Stage`, `SalesLead`, `SalesOpportunity`, `SalesPipelineSnapshot`
- `Project`, `ProjectStage`, `ProjectHealthSnapshot`
- `SupportContract`, `SupportUsagePeriod`
- `Task`, `TaskTimeEntry`
- `RevenueTransaction`, `ExpenseTransaction`, `CashflowEntry`
- `SourceConnection`, `SourceSyncLog`, `SourceMapping`
- `Alert`, `MetricSnapshot`, `ManualAdjustment`
- `DataImportBatch`, `BankStatementRow`, `ProfitabilitySnapshot`

### Экранная структура
- `/admin` - Filament v5-панель и главный обзор
- `/admin/sales` - продажи и pipeline
- `/admin/companies` - amo-компании и метрики по ним
- `/admin/production` - производство и загрузка команды
- `/admin/support` - сопровождение и движение сделок
- `/admin/finance` - деньги, cashflow, маржинальность
- `/admin/risks` - красные зоны и алерты
- `/admin/integrations` - источники и синхронизация
- `/admin/manual-adjustments` - ручные корректировки
- `/admin/bank-import` - импорт банковской выписки

## Docker Compose

### Локально
- `app` - PHP 8.3 + Laravel
- `postgres` - PostgreSQL 16
- `scheduler` - `php artisan schedule:work`
- `node` - dev-only Vite server, включается профилем `dev`

### Прод
- `docker-compose.prod.yml` использует тот же image
- без nginx, без redis, без очередей
- приложение запускается через `php artisan serve`

## Запуск

### 1. Поднять сервисы
```bash
docker compose up --build
```

Если нужен Vite dev-server внутри compose:
```bash
docker compose --profile dev up --build
```

### 2. Наполнить демо-данными
```bash
docker compose exec app php artisan app:seed-demo-dashboard
```

### 3. Запустить импорт/синк вручную
```bash
docker compose exec app php artisan sources:sync --all
docker compose exec app php artisan analytics:refresh-snapshots
```

### 4. Открыть приложение
- `http://localhost:8000`
- Filament-панель: `http://localhost:8000/admin`

### 5. Логин демо-пользователя
- email: `owner@example.com`
- password: `password`

## Банковский импорт

Поддерживается CSV/XLSX.

Форма импорта ожидает поля:
- date
- amount
- direction
- counterparty
- purpose
- category

Поля можно переопределить в UI при загрузке файла.

## Интеграции

Слой интеграций сделан как модульная абстракция:
- `app/Services/Integrations/Contracts/SourceConnector.php`
- `app/Services/Integrations/SourceSyncService.php`
- `app/Services/Integrations/Connectors/*`

amoCRM и Weeek поддерживают два режима:
- demo-mode, если не заполнены credentials
- real sync, если заданы переменные окружения

Для amoCRM:
- `AMO_BASE_URL`
- `AMO_ACCESS_TOKEN`
- `AMO_ALLOWED_PIPELINE_NAMES` - список воронок, которые нужно синкать. По умолчанию: `Основная,Повторные,Виджеты,Сопровождение`
- `AMO_EXCLUDED_PIPELINE_NAMES` - опциональный fallback для blacklist-режима
- `AMO_REFRESH_TOKEN`, `AMO_CLIENT_ID`, `AMO_CLIENT_SECRET`, `AMO_REDIRECT_URI` - опционально, только если когда-нибудь понадобится OAuth-режим

Синк amoCRM сейчас забирает:
- сделки только из разрешенных воронок
- только активные и успешные сделки
- компании, связанные со сделками
- связи через компании, без опоры на контакты

Для Weeek:
- `WEEEK_BASE_URL`
- `WEEEK_TOKEN`

В системе один владелец и один контекст аккаунта. Переключение компаний больше не используется.

## Filament и плагины

Панель собрана на Filament v5 и использует:
- `leandrocfe/filament-apex-charts` для более гибких графиков и сравнения периодов
- `pxlrbt/filament-excel` для Filament-таблиц с экспортом

На главном обзоре теперь есть:
- сравнение текущего и предыдущего периода
- графики с детализацией по дням
- экспортируемый drill-down по крупным сделкам

## Алерты

Система риска хранит правила в `config/dashboard.php`.

Триггеры:
- проект без активности N дней
- перерасход часов по проекту
- перерасход сопровождения
- сделка без движения
- источник давно не синкался
- низкая маржинальность клиента
- расходы выше поступлений

## Демоданные

После сидов в системе есть:
- 20+ лидов
- 20+ сделок
- 10+ проектов
- 5+ сотрудников
- time entries
- support contracts
- revenue / expense / cashflow
- source connections
- alerts
- metric snapshots

## Следующие шаги

1. Подключить реальные API amoCRM и Weeek через настройки источников.
2. Добавить полноценный mapping UI для `source_mappings`.
3. Доработать импорт банковской выписки с сохранением шаблонов колонок.
4. Добавить план-факт по часам и бюджетам на уровне проекта.
5. Расширить profitability snapshots по клиентам и по проектам.
6. Сделать дашборд уведомлений с приоритетами и подтверждением алертов.
7. Добавить экспорт PDF/CSV для управленческих отчетов.
8. Вынести расчет метрик в отдельный cron/command lifecycle, если данных станет больше.
