<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pronomix\BookStackOpenWebUISync\Models\OpenWebUISyncTask;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class ProcessQueueCommand extends Command
{
    protected $signature = 'openwebui:process-queue {--limit=}';
    protected $description = 'Process pending OpenWebUI sync tasks.';

    public function handle(OpenWebUISyncService $sync): int
    {
        if (!$sync->isEnabled()) {
            $this->info('OpenWebUI sync is disabled.');
            return Command::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?? config('bookstack-openwebui.queue.max_tasks_per_run'));
        $limit = $limit > 0 ? $limit : 25;
        $channel = $this->resolveLogChannel();

        $tasks = OpenWebUISyncTask::query()->ready()->orderBy('available_at')->limit($limit)->get();
        $pendingCount = OpenWebUISyncTask::query()->where('status', OpenWebUISyncTask::STATUS_PENDING)->count();

        Log::channel($channel)->info('OpenWebUI queue run started', [
            'limit' => $limit,
            'ready_count' => $tasks->count(),
            'pending_total' => $pendingCount,
        ]);

        foreach ($tasks as $task) {
            $task->markProcessing();
            $correlationId = (string) Str::uuid();

            Log::channel($channel)->withContext(['openwebui_task_id' => $task->id, 'correlation_id' => $correlationId]);
            Log::channel($channel)->info('OpenWebUI task started', [
                'task_id' => $task->id,
                'task_type' => $task->task_type,
                'attempt' => $task->attempts,
            ]);

            try {
                $sync->handleTask($task);
                $task->markDone();
                Log::channel($channel)->info('OpenWebUI task completed', [
                    'task_id' => $task->id,
                    'task_type' => $task->task_type,
                ]);
            } catch (\Throwable $e) {
                $maxAttempts = (int) config('bookstack-openwebui.queue.max_attempts');
                $backoff = (int) config('bookstack-openwebui.queue.backoff_seconds');
                $retryAt = null;
                $responseContext = [];

                if ($task->attempts < $maxAttempts) {
                    $retryAt = now()->addSeconds($backoff);
                }

                if ($e instanceof RequestException) {
                    $response = $e->response;
                    $responseContext = [
                        'http_status' => $response?->status(),
                        'http_body' => $response?->body(),
                    ];
                }

                $task->markFailed($e->getMessage(), $retryAt);

                Log::channel($channel)->error('OpenWebUI task failed', [
                    'task_id' => $task->id,
                    'task_type' => $task->task_type,
                    'error' => $e->getMessage(),
                    'response' => $responseContext,
                ]);
            }
        }

        Log::channel($channel)->info('OpenWebUI queue run finished', [
            'processed' => $tasks->count(),
        ]);

        $this->info('Processed ' . $tasks->count() . ' task(s).');

        return Command::SUCCESS;
    }

    private function resolveLogChannel(): string
    {
        $channel = config('bookstack-openwebui.log_channel');
        if (is_string($channel) && $channel !== '' && config('logging.channels.' . $channel)) {
            return $channel;
        }

        $default = (string) config('logging.default');
        if ($default !== '' && config('logging.channels.' . $default)) {
            return $default;
        }

        return 'stack';
    }
}
