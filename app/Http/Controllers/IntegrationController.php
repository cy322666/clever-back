<?php

namespace App\Http\Controllers;

use App\Models\SourceConnection;
use App\Models\Project;
use App\Services\Integrations\SourceConnectionBootstrapper;
use App\Services\Integrations\SourceSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(SourceConnectionBootstrapper $bootstrapper): View
    {
        $allowedSourceKeys = array_keys(config('integrations.sources', []));
        $bootstrapper->ensureDefaults();

        return view('integrations.index', [
            'connections' => SourceConnection::query()
                ->whereIn('source_key', $allowedSourceKeys)
                ->latest()
                ->get(),
            'weeekProjects' => Project::query()
                ->whereNotNull('external_id')
                ->orderByRaw("case when status = 'active' then 0 else 1 end")
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function updateSettings(Request $request, SourceConnection $sourceConnection): RedirectResponse
    {
        abort_unless($sourceConnection->source_key === 'weeek', 404);

        $data = $request->validate([
            'settings.sync_archived_projects' => ['nullable', 'boolean'],
            'settings.excluded_project_ids' => ['nullable', 'array'],
            'settings.excluded_project_ids.*' => ['string'],
        ]);

        $settings = array_merge($sourceConnection->settings ?? [], [
            'sync_archived_projects' => (bool) data_get($data, 'settings.sync_archived_projects', false),
            'excluded_project_ids' => array_values(array_filter(array_map('strval', data_get($data, 'settings.excluded_project_ids', [])))),
        ]);

        $sourceConnection->update(['settings' => $settings]);

        return back()->with('status', 'Настройки Weeek обновлены');
    }

    public function sync(SourceConnection $sourceConnection, SourceSyncService $syncService): RedirectResponse
    {
        if (! array_key_exists($sourceConnection->source_key, config('integrations.sources', []))) {
            return back()->with('status', 'Этот источник отключен и больше не синхронизируется.');
        }

        $syncService->syncConnection($sourceConnection);

        return back()->with('status', 'Синхронизация запущена для '.$sourceConnection->name);
    }

    public function syncAll(SourceSyncService $syncService): RedirectResponse
    {
        $processed = $syncService->syncAll();

        return back()->with('status', $processed > 0
            ? 'Синхронизация запущена для '.$processed.' источников'
            : 'Нет активных источников для синка');
    }
}
