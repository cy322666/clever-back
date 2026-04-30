<?php

namespace App\Http\Controllers;

use App\Services\Imports\BankStatementImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankImportController extends Controller
{
    public function create(): View
    {
        return view('imports.bank');
    }

    public function store(Request $request, BankStatementImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,xlsx,xls'],
            'date_column' => ['nullable', 'string'],
            'amount_column' => ['nullable', 'string'],
            'direction_column' => ['nullable', 'string'],
            'counterparty_column' => ['nullable', 'string'],
            'purpose_column' => ['nullable', 'string'],
            'category_column' => ['nullable', 'string'],
        ]);

        $service->import($data['file'], [
            'date' => $data['date_column'] ?? 'date',
            'amount' => $data['amount_column'] ?? 'amount',
            'direction' => $data['direction_column'] ?? 'direction',
            'counterparty' => $data['counterparty_column'] ?? 'counterparty',
            'purpose' => $data['purpose_column'] ?? 'purpose',
            'category' => $data['category_column'] ?? 'category',
        ]);

        return back()->with('status', 'Файл выписки импортирован.');
    }
}
