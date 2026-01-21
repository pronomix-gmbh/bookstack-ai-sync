<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Pronomix\BookStackOpenWebUISync\Models\OpenWebUISyncTask;
use Pronomix\BookStackOpenWebUISync\Services\BookStackRepository;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUIClient;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;
use Pronomix\BookStackOpenWebUISync\Services\SettingsRepository;

class SettingsController extends Controller
{
    public function index(SettingsRepository $settings, BookStackRepository $bookStack)
    {
        $apiKey = $settings->get('api_key', config('bookstack-openwebui.openwebui.api_key'), true);

        $data = [
            'enabled' => (bool) $settings->get('enabled', config('bookstack-openwebui.enabled')),
            'instance_name' => (string) $settings->get('instance_name', config('bookstack-openwebui.instance_name')),
            'base_url' => (string) $settings->get('base_url', config('bookstack-openwebui.openwebui.base_url')),
            'timeout' => (int) $settings->get('timeout', config('bookstack-openwebui.openwebui.timeout')),
            'verify_tls' => (bool) $settings->get('verify_tls', config('bookstack-openwebui.openwebui.verify_tls')),
            'polling_enabled' => (bool) $settings->get('polling_enabled', config('bookstack-openwebui.polling.enabled')),
            'polling_interval_minutes' => (int) $settings->get('polling_interval_minutes', config('bookstack-openwebui.polling.interval_minutes')),
            'has_api_key' => !empty($apiKey),
            'books' => $bookStack->listBooks(),
            'queue_pending' => OpenWebUISyncTask::query()->where('status', OpenWebUISyncTask::STATUS_PENDING)->count(),
            'queue_failed' => OpenWebUISyncTask::query()->where('status', OpenWebUISyncTask::STATUS_FAILED)->count(),
            'recent_failures' => OpenWebUISyncTask::query()
                ->where('status', OpenWebUISyncTask::STATUS_FAILED)
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(),
        ];

        return view('bookstack-openwebui::settings.index', $data);
    }

    public function update(Request $request, SettingsRepository $settings): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'nullable|boolean',
            'instance_name' => 'nullable|string|max:255',
            'base_url' => 'nullable|url',
            'api_key' => 'nullable|string',
            'timeout' => 'nullable|integer|min:1|max:120',
            'verify_tls' => 'nullable|boolean',
            'polling_enabled' => 'nullable|boolean',
            'polling_interval_minutes' => 'nullable|integer|min:1|max:120',
        ]);

        $validator->validate();

        $settings->set('enabled', $request->boolean('enabled'));
        $settings->set('instance_name', (string) $request->input('instance_name'));
        $settings->set('base_url', (string) $request->input('base_url'));
        $settings->set('timeout', (string) $request->input('timeout', 30));
        $settings->set('verify_tls', $request->boolean('verify_tls'));
        $settings->set('polling_enabled', $request->boolean('polling_enabled'));
        $settings->set('polling_interval_minutes', (string) $request->input('polling_interval_minutes', 5));

        if ($request->filled('api_key')) {
            $settings->set('api_key', (string) $request->input('api_key'), true);
        }

        return redirect()->route('openwebui.settings.index')->with('success', 'Settings saved.');
    }

    public function test(OpenWebUIClient $client, OpenWebUISyncService $sync): RedirectResponse
    {
        if (!$sync->isEnabled()) {
            return redirect()->route('openwebui.settings.index')
                ->with('warning', 'OpenWebUI sync is disabled.');
        }

        try {
            $knowledge = $client->listKnowledge();
        } catch (\Throwable $e) {
            return redirect()->route('openwebui.settings.index')
                ->with('error', 'Connection failed: ' . $e->getMessage());
        }

        $count = is_array($knowledge) ? count($knowledge) : 0;

        return redirect()->route('openwebui.settings.index')
            ->with('success', 'Connection OK. Knowledge entries: ' . $count);
    }

    public function syncAll(OpenWebUISyncService $sync): RedirectResponse
    {
        if (!$sync->isEnabled()) {
            return redirect()->route('openwebui.settings.index')
                ->with('warning', 'OpenWebUI sync is disabled.');
        }

        $sync->enqueueSyncAll();

        return redirect()->route('openwebui.settings.index')
            ->with('success', 'Sync tasks enqueued for all books.');
    }

    public function syncBook(Request $request, OpenWebUISyncService $sync): RedirectResponse
    {
        if (!$sync->isEnabled()) {
            return redirect()->route('openwebui.settings.index')
                ->with('warning', 'OpenWebUI sync is disabled.');
        }

        $bookId = (int) $request->input('book_id');

        if ($bookId > 0) {
            $sync->enqueueSyncBook($bookId);
            return redirect()->route('openwebui.settings.index')
                ->with('success', 'Sync tasks enqueued for book ' . $bookId . '.');
        }

        return redirect()->route('openwebui.settings.index')
            ->with('error', 'Please choose a book.');
    }

    public function rebuildBook(Request $request, OpenWebUISyncService $sync): RedirectResponse
    {
        if (!$sync->isEnabled()) {
            return redirect()->route('openwebui.settings.index')
                ->with('warning', 'OpenWebUI sync is disabled.');
        }

        $bookId = (int) $request->input('book_id');

        if ($bookId > 0) {
            $sync->enqueueTask(OpenWebUISyncService::TASK_REBUILD_BOOK, ['book_id' => $bookId]);
            return redirect()->route('openwebui.settings.index')
                ->with('success', 'Rebuild task enqueued for book ' . $bookId . '.');
        }

        return redirect()->route('openwebui.settings.index')
            ->with('error', 'Please choose a book.');
    }
}
