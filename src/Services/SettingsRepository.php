<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Services;

use Illuminate\Support\Facades\Crypt;
use Pronomix\BookStackOpenWebUISync\Models\OpenWebUISetting;

class SettingsRepository
{
    private const PREFIX = 'openwebui.';

    public function get(string $key, mixed $default = null, bool $decrypt = false): mixed
    {
        $fullKey = self::PREFIX . $key;

        $value = $this->getFromBookStack($fullKey, $default);

        if ($value !== null) {
            return $this->maybeDecrypt($value, $decrypt);
        }

        $record = OpenWebUISetting::query()->where('key', $fullKey)->first();

        if (!$record) {
            return $default;
        }

        $stored = $record->value;

        if ($record->is_encrypted) {
            return $this->maybeDecrypt($stored, true);
        }

        return $this->maybeDecrypt($stored, $decrypt);
    }

    public function set(string $key, mixed $value, bool $encrypt = false): void
    {
        $fullKey = self::PREFIX . $key;

        $stored = $encrypt ? Crypt::encryptString((string) $value) : (string) $value;

        if ($this->setInBookStack($fullKey, $stored)) {
            return;
        }

        OpenWebUISetting::query()->updateOrCreate(
            ['key' => $fullKey],
            ['value' => $stored, 'is_encrypted' => $encrypt]
        );
    }

    public function delete(string $key): void
    {
        $fullKey = self::PREFIX . $key;

        if ($this->setInBookStack($fullKey, null)) {
            return;
        }

        OpenWebUISetting::query()->where('key', $fullKey)->delete();
    }

    private function maybeDecrypt(mixed $value, bool $decrypt): mixed
    {
        if (!$decrypt) {
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function getFromBookStack(string $key, mixed $default): mixed
    {
        if (function_exists('setting')) {
            try {
                $direct = setting($key, $default);
                if (!is_object($direct)) {
                    return $direct;
                }
                if (method_exists($direct, 'get')) {
                    return $direct->get($key, $default);
                }
            } catch (\Throwable $e) {
                // Fall through.
            }
        }

        if (app()->bound('setting')) {
            $service = app('setting');
            if (method_exists($service, 'get')) {
                return $service->get($key, $default);
            }
            if (method_exists($service, 'getValue')) {
                return $service->getValue($key, $default);
            }
        }

        return null;
    }

    private function setInBookStack(string $key, mixed $value): bool
    {
        if (function_exists('setting')) {
            try {
                $service = setting();
                if (method_exists($service, 'put')) {
                    $service->put($key, $value);
                    return true;
                }
                if (method_exists($service, 'set')) {
                    $service->set($key, $value);
                    return true;
                }
            } catch (\Throwable $e) {
                return false;
            }
        }

        if (app()->bound('setting')) {
            $service = app('setting');
            if (method_exists($service, 'put')) {
                $service->put($key, $value);
                return true;
            }
            if (method_exists($service, 'set')) {
                $service->set($key, $value);
                return true;
            }
        }

        return false;
    }
}
