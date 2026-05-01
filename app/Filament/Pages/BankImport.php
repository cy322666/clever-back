<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BankImportsTableWidget;
use App\Services\Imports\BankStatementImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class BankImport extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;
    protected static ?string $navigationLabel = 'Импорт выписки';
    protected static ?string $title = 'Импорт банковской выписки';
    protected static ?int $navigationSort = 10;

    protected function widgets(): array
    {
        return [
            $this->widget(BankImportsTableWidget::class),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Импортировать выписку')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->form([
                    FileUpload::make('file')
                        ->label('Файл')
                        ->required()
                        ->preserveFilenames()
                        ->disk('local')
                        ->directory('imports/tmp'),
                    TextInput::make('date_column')->label('Колонка даты')->default('date'),
                    TextInput::make('amount_column')->label('Колонка суммы')->default('amount'),
                    TextInput::make('direction_column')->label('Колонка типа операции')->default('direction'),
                    TextInput::make('counterparty_column')->label('Колонка клиента')->default('counterparty'),
                    TextInput::make('purpose_column')->label('Колонка назначения платежа')->default('purpose'),
                    TextInput::make('category_column')->label('Колонка категории')->default('category'),
                ])
                ->action(function (array $data): void {
                    $filePath = Storage::path($data['file']);
                    $uploadedFile = new UploadedFile(
                        $filePath,
                        basename($filePath),
                        mime_content_type($filePath) ?: null,
                        null,
                        true,
                    );

                    app(BankStatementImportService::class)->import($uploadedFile, [
                        'date' => $data['date_column'] ?? 'date',
                        'amount' => $data['amount_column'] ?? 'amount',
                        'direction' => $data['direction_column'] ?? 'direction',
                        'counterparty' => $data['counterparty_column'] ?? 'counterparty',
                        'purpose' => $data['purpose_column'] ?? 'purpose',
                        'category' => $data['category_column'] ?? 'category',
                    ]);

                    Notification::make()
                        ->title('Файл выписки импортирован')
                        ->success()
                        ->send();
                }),
        ];
    }
}
