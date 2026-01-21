<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Pronomix\BookStackOpenWebUISync\Services\SettingsRepository;

class UninstallCommand extends Command
{
    protected $signature = 'openwebui:uninstall {--force : Run without confirmation} {--drop : Skip migrate:rollback and drop tables directly} {--purge-settings : Remove stored settings keys}';
    protected $description = 'Uninstall OpenWebUI sync tables and metadata.';

    public function handle(SettingsRepository $settings): int
    {
        if (!$this->option('force')) {
            $confirmed = $this->confirm('This will remove OpenWebUI sync tables and data. Continue?', false);
            if (!$confirmed) {
                $this->info('Uninstall cancelled.');
                return Command::SUCCESS;
            }
        }

        $migrationPath = $this->migrationPath();
        $migrationNames = $this->migrationNames($migrationPath);

        if (!$this->option('drop')) {
            $this->call('migrate:rollback', [
                '--path' => $migrationPath,
                '--force' => true,
            ]);
        }

        if (!empty($migrationNames)) {
            DB::table('migrations')->whereIn('migration', $migrationNames)->delete();
        }

        foreach (['openwebui_knowledge_map', 'openwebui_file_map', 'openwebui_sync_tasks', 'openwebui_settings'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }

        if ($this->option('purge-settings')) {
            foreach ([
                'enabled',
                'instance_name',
                'base_url',
                'api_key',
                'timeout',
                'verify_tls',
                'polling_enabled',
                'polling_interval_minutes',
                'poll_last_seen',
                'queue_dispatch',
            ] as $key) {
                $settings->delete($key);
            }
        }

        $this->info('OpenWebUI sync uninstalled.');

        return Command::SUCCESS;
    }

    private function migrationPath(): string
    {
        $absolute = realpath(__DIR__ . '/../../../database/migrations');

        if (!$absolute) {
            return 'vendor/pronomix/bookstack-openwebui-sync/database/migrations';
        }

        $base = base_path();

        if (Str::startsWith($absolute, $base)) {
            return ltrim(Str::after($absolute, $base), DIRECTORY_SEPARATOR);
        }

        return $absolute;
    }

    private function migrationNames(string $path): array
    {
        $absolute = $path;

        if (!Str::startsWith($path, DIRECTORY_SEPARATOR)) {
            $absolute = base_path($path);
        }

        $files = glob(rtrim($absolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];

        return array_map(static function (string $file): string {
            return pathinfo($file, PATHINFO_FILENAME);
        }, $files);
    }
}
