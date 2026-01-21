<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
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

        if ($sync->shouldPoll(now())) {
            $sync->enqueueTask(OpenWebUISyncService::TASK_POLL_CHANGES);
        }

        $tasks = OpenWebUISyncTask::query()->ready()->orderBy('available_at')->limit($limit)->get();

        foreach ($tasks as $task) {
            $task->markProcessing();
            $correlationId = (string) Str::uuid();

            $channel = config('bookstack-openwebui.log_channel') ?? config('logging.default');
            Log::channel($channel)->withContext(['openwebui_task_id' => $task->id, 'correlation_id' => $correlationId]);

            try {
                $sync->handleTask($task);
                $task->markDone();
            } catch (\Throwable $e) {
                $maxAttempts = (int) config('bookstack-openwebui.queue.max_attempts');
                $backoff = (int) config('bookstack-openwebui.queue.backoff_seconds');
                $retryAt = null;

                if ($task->attempts < $maxAttempts) {
                    $retryAt = now()->addSeconds($backoff);
                }

                $task->markFailed($e->getMessage(), $retryAt);

                $channel = config('bookstack-openwebui.log_channel') ?? config('logging.default');
                Log::channel($channel)->error('OpenWebUI task failed', [
                        'task_id' => $task->id,
                        'task_type' => $task->task_type,
                        'error' => $e->getMessage(),
                    ]);
            }
        }

        $this->info('Processed ' . $tasks->count() . ' task(s).');

        return Command::SUCCESS;
    }
}
