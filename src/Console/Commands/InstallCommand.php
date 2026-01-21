<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'openwebui:install {--publish-config} {--publish-views}';
    protected $description = 'Install OpenWebUI sync tables and optional assets.';

    public function handle(): int
    {
        $path = $this->migrationPath();

        $this->call('migrate', [
            '--path' => $path,
            '--force' => true,
        ]);

        if ($this->option('publish-config')) {
            $this->call('vendor:publish', [
                '--tag' => 'bookstack-openwebui-config',
                '--force' => true,
            ]);
        }

        if ($this->option('publish-views')) {
            $this->call('vendor:publish', [
                '--tag' => 'bookstack-openwebui-views',
                '--force' => true,
            ]);
        }

        $this->info('OpenWebUI sync installed.');

        return Command::SUCCESS;
    }

    private function migrationPath(): string
    {
        $absolute = realpath(__DIR__ . '/../../../database/migrations');

        if (!$absolute) {
            return 'vendor/pronomix-gmbh/bookstack-ai-sync/database/migrations';
        }

        $base = base_path();

        if (Str::startsWith($absolute, $base)) {
            return ltrim(Str::after($absolute, $base), DIRECTORY_SEPARATOR);
        }

        return $absolute;
    }
}
