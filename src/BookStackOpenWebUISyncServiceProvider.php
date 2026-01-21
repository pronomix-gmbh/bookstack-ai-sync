<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync;

use Illuminate\Support\ServiceProvider;
use Pronomix\BookStackOpenWebUISync\Console\Commands\InstallCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\ClearQueueCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\ListQueueCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\ProcessQueueCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\SyncAllCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\SyncBookCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\TestConnectionCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\UninstallCommand;
use Pronomix\BookStackOpenWebUISync\Observers\BookObserver;
use Pronomix\BookStackOpenWebUISync\Observers\AttachmentObserver;
use Pronomix\BookStackOpenWebUISync\Observers\ImageObserver;
use Pronomix\BookStackOpenWebUISync\Observers\PageObserver;

class BookStackOpenWebUISyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $defaults = $this->defaultConfig();
        $existing = $this->app['config']->get('bookstack-openwebui', []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $this->app['config']->set(
            'bookstack-openwebui',
            array_replace_recursive($defaults, $existing)
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearQueueCommand::class,
                InstallCommand::class,
                ListQueueCommand::class,
                ProcessQueueCommand::class,
                SyncAllCommand::class,
                SyncBookCommand::class,
                TestConnectionCommand::class,
                UninstallCommand::class,
            ]);
        }

        $this->registerObservers();
    }

    private function defaultConfig(): array
    {
        return [
            'enabled' => env('OPENWEBUI_ENABLED', false),
            'instance_name' => env('OPENWEBUI_INSTANCE_NAME', env('APP_NAME', 'bookstack')),
            'openwebui' => [
                'base_url' => env('OPENWEBUI_BASE_URL', ''),
                'api_key' => env('OPENWEBUI_API_KEY', ''),
                'timeout' => (int) env('OPENWEBUI_TIMEOUT', 30),
                'verify_tls' => env('OPENWEBUI_VERIFY_TLS', true),
                'paths' => [
                    'knowledge_list' => '/api/v1/knowledge/',
                    'knowledge_create' => '/api/v1/knowledge/create',
                    'file_upload' => '/api/v1/files/',
                    'knowledge_file_add' => '/api/v1/knowledge/{id}/file/add',
                    'file_delete' => '/api/v1/files/{id}',
                    'knowledge_delete' => '/api/v1/knowledge/{id}/delete',
                    'knowledge_get' => '/api/v1/knowledge/{id}',
                    'model_list' => '/api/v1/models/list',
                    'model_get' => '/api/v1/models/model',
                    'model_create' => '/api/v1/models/create',
                    'model_update' => '/api/v1/models/model/update',
                ],
            ],
            'workspace' => [
                'enabled' => env('OPENWEBUI_WORKSPACE_ENABLED', true),
                'model_id' => env('OPENWEBUI_WORKSPACE_MODEL_ID', ''),
                'model_name' => env('OPENWEBUI_WORKSPACE_MODEL_NAME', ''),
            ],
            'queue' => [
                'dispatch_jobs' => env('OPENWEBUI_QUEUE_DISPATCH', false),
                'max_attempts' => (int) env('OPENWEBUI_MAX_ATTEMPTS', 5),
                'backoff_seconds' => (int) env('OPENWEBUI_BACKOFF_SECONDS', 60),
                'max_tasks_per_run' => (int) env('OPENWEBUI_MAX_TASKS_PER_RUN', 25),
            ],
            'log_channel' => env('OPENWEBUI_LOG_CHANNEL', null),
        ];
    }

    protected function registerObservers(): void
    {
        if (class_exists('BookStack\\Entities\\Models\\Book')) {
            \BookStack\Entities\Models\Book::observe(BookObserver::class);
        }

        if (class_exists('BookStack\\Entities\\Models\\Page')) {
            \BookStack\Entities\Models\Page::observe(PageObserver::class);
        }

        foreach (['BookStack\\Entities\\Models\\Attachment', 'BookStack\\Uploads\\Attachment'] as $class) {
            if (class_exists($class)) {
                $class::observe(AttachmentObserver::class);
            }
        }

        foreach (['BookStack\\Entities\\Models\\Image', 'BookStack\\Uploads\\Image'] as $class) {
            if (class_exists($class)) {
                $class::observe(ImageObserver::class);
            }
        }
    }
}
