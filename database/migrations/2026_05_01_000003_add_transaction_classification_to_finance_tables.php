<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addColumns('revenue_transactions', 'income');
        $this->addColumns('expense_transactions', 'expense');
        $this->backfillTypes();
    }

    public function down(): void
    {
        $this->dropColumns('expense_transactions');
        $this->dropColumns('revenue_transactions');
    }

    private function addColumns(string $tableName, string $direction): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $direction): void {
            if (! Schema::hasColumn($tableName, 'direction')) {
                $table->string('direction')->default($direction)->after('amount')->index();
            }

            if (! Schema::hasColumn($tableName, 'transaction_type')) {
                $table->string('transaction_type')->nullable()->after('direction')->index();
            }

            if (! Schema::hasColumn($tableName, 'invoice_id') && Schema::hasTable('invoices')) {
                $table->foreignId('invoice_id')->nullable()->after('bank_statement_row_id')->constrained('invoices')->nullOnDelete();
            }
        });

        DB::table($tableName)
            ->where(function ($query) {
                $query->whereNull('direction')->orWhere('direction', '');
            })
            ->update(['direction' => $direction]);
    }

    private function dropColumns(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (Schema::hasColumn($tableName, 'invoice_id')) {
                $table->dropConstrainedForeignId('invoice_id');
            }

            if (Schema::hasColumn($tableName, 'transaction_type')) {
                $table->dropColumn('transaction_type');
            }

            if (Schema::hasColumn($tableName, 'direction')) {
                $table->dropColumn('direction');
            }
        });
    }

    private function backfillTypes(): void
    {
        if (Schema::hasTable('revenue_transactions') && Schema::hasColumn('revenue_transactions', 'transaction_type')) {
            DB::statement("
                update revenue_transactions
                set transaction_type = case
                    when lower(coalesce(category, channel, note, '')) like '%сопров%' then 'Сопровождение'
                    when lower(coalesce(category, channel, note, '')) like '%лиценз%' then 'Лицензии'
                    when lower(coalesce(category, channel, note, '')) like '%виджет%' then 'Виджеты'
                    when lower(coalesce(category, channel, note, '')) like '%разраб%' then 'Разработка'
                    when lower(coalesce(category, channel, note, '')) like '%аудит%' then 'Аудит'
                    when lower(coalesce(category, channel, note, '')) like '%конс%' then 'Консультация'
                    when lower(coalesce(category, channel, note, '')) like '%пакет%' then 'Пакет часов'
                    when lower(coalesce(category, channel, note, '')) like '%перевнедр%' then 'Перевнедрение'
                    when lower(coalesce(category, channel, note, '')) like '%внедр%' then 'Внедрение'
                    else transaction_type
                end
                where transaction_type is null or transaction_type = ''
            ");
        }

        if (Schema::hasTable('expense_transactions') && Schema::hasColumn('expense_transactions', 'transaction_type')) {
            DB::statement("
                update expense_transactions
                set transaction_type = case
                    when lower(coalesce(category, vendor_name, note, '')) like '%зарп%' then 'ФОТ'
                    when lower(coalesce(category, vendor_name, note, '')) like '%фот%' then 'ФОТ'
                    when lower(coalesce(category, vendor_name, note, '')) like '%подряд%' then 'Подрядчики'
                    when lower(coalesce(category, vendor_name, note, '')) like '%сервис%' then 'Сервисы'
                    when lower(coalesce(category, vendor_name, note, '')) like '%маркет%' then 'Реклама / маркетинг'
                    when lower(coalesce(category, vendor_name, note, '')) like '%реклам%' then 'Реклама / маркетинг'
                    when lower(coalesce(category, vendor_name, note, '')) like '%налог%' then 'Налоги'
                    when lower(coalesce(category, vendor_name, note, '')) like '%офис%' then 'Офис / аренда'
                    when lower(coalesce(category, vendor_name, note, '')) like '%аренд%' then 'Офис / аренда'
                    when lower(coalesce(category, vendor_name, note, '')) like '%комисс%' then 'Банковские комиссии'
                    when lower(coalesce(category, vendor_name, note, '')) like '%возврат%' then 'Возвраты'
                    else transaction_type
                end
                where transaction_type is null or transaction_type = ''
            ");
        }
    }
};
