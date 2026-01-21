<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class OpenWebUIClient
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function listKnowledge(): array
    {
        $response = $this->request()->get($this->path('knowledge_list'));
        $response->throw();

        $data = $response->json();

        if (is_array($data)) {
            return $data;
        }

        return [];
    }

    public function createKnowledge(string $name): array
    {
        $response = $this->request()->post($this->path('knowledge_create'), [
            'name' => $name,
        ]);
        $response->throw();

        return $response->json() ?? [];
    }

    public function uploadFile(string $filename, mixed $contents): array
    {
        $response = $this->request()
            ->attach('file', $contents, $filename)
            ->post($this->path('file_upload'));

        $response->throw();

        return $response->json() ?? [];
    }

    public function addFileToKnowledge(string $knowledgeId, string $fileId): array
    {
        $response = $this->request()->post($this->path('knowledge_file_add', $knowledgeId), [
            'file_id' => $fileId,
        ]);

        $response->throw();

        return $response->json() ?? [];
    }

    public function deleteFile(string $fileId): void
    {
        $response = $this->request()->delete($this->path('file_delete', $fileId));
        $response->throw();
    }

    public function deleteKnowledge(string $knowledgeId): void
    {
        $response = $this->request()->delete($this->path('knowledge_delete', $knowledgeId));
        $response->throw();
    }

    public function extractId(array $response): ?string
    {
        foreach (['id', 'data.id', 'data.file_id', 'file_id', 'knowledge_id'] as $key) {
            $value = Arr::get($response, $key);
            if ($value) {
                return (string) $value;
            }
        }

        return null;
    }

    private function request(): PendingRequest
    {
        $baseUrl = (string) $this->settings->get('base_url', config('bookstack-openwebui.openwebui.base_url'));
        $apiKey = (string) $this->settings->get('api_key', config('bookstack-openwebui.openwebui.api_key'), true);
        $timeout = (int) $this->settings->get('timeout', config('bookstack-openwebui.openwebui.timeout'));
        $verify = (bool) $this->settings->get('verify_tls', config('bookstack-openwebui.openwebui.verify_tls'));

        if ($baseUrl === '') {
            throw new \RuntimeException('OpenWebUI base URL is not configured');
        }

        $request = Http::baseUrl(rtrim($baseUrl, '/'))
            ->timeout($timeout)
            ->acceptJson();

        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        return $request->withOptions(['verify' => $verify])->retry(2, 250);
    }

    private function path(string $key, string $id = null): string
    {
        $path = config('bookstack-openwebui.openwebui.paths.' . $key, '');

        if ($id) {
            $path = str_replace('{id}', $id, $path);
        }

        return $path;
    }
}
