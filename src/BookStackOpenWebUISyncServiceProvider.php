<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Pronomix\BookStackOpenWebUISync\Console\Commands\InstallCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\ClearQueueCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\ProcessQueueCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\RebuildBookCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\SyncAllCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\SyncBookCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\TestConnectionCommand;
use Pronomix\BookStackOpenWebUISync\Console\Commands\UninstallCommand;
use Pronomix\BookStackOpenWebUISync\Http\Middleware\AdminMiddleware;
use Pronomix\BookStackOpenWebUISync\Observers\BookObserver;
use Pronomix\BookStackOpenWebUISync\Observers\AttachmentObserver;
use Pronomix\BookStackOpenWebUISync\Observers\ImageObserver;
use Pronomix\BookStackOpenWebUISync\Observers\PageObserver;

class BookStackOpenWebUISyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bookstack-openwebui.php', 'bookstack-openwebui');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'bookstack-openwebui');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/bookstack-openwebui.php' => config_path('bookstack-openwebui.php'),
        ], 'bookstack-openwebui-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/bookstack-openwebui'),
        ], 'bookstack-openwebui-views');

        Route::aliasMiddleware('openwebui.admin', AdminMiddleware::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearQueueCommand::class,
                InstallCommand::class,
                ProcessQueueCommand::class,
                SyncAllCommand::class,
                SyncBookCommand::class,
                RebuildBookCommand::class,
                TestConnectionCommand::class,
                UninstallCommand::class,
            ]);
        }

        $this->registerObservers();
    }

    protected function registerObservers(): void
    {
        if (class_exists('BookStack\\Entities\\Models\\Book')) {
            \BookStack\Entities\Models\Book::observe(BookObserver::class);
        }

        if (class_exists('BookStack\\Entities\\Models\\Page')) {
            \BookStack\Entities\Models\Page::observe(PageObserver::class);
        }

        foreach (['BookStack\\Uploads\\Attachment', 'BookStack\\Entities\\Models\\Attachment'] as $class) {
            if (class_exists($class)) {
                $class::observe(AttachmentObserver::class);
                break;
            }
        }

        if (class_exists('BookStack\\Uploads\\Image')) {
            \BookStack\Uploads\Image::observe(ImageObserver::class);
        }
    }
}
