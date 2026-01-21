<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class OpenWebUIClient
{
    public function __construct()
    {
    }

    public function listKnowledge(): array
    {
        $response = $this->request()->get($this->path('knowledge_list'));
        $response->throw();

        $data = $response->json();

        if (!is_array($data)) {
            return [];
        }

        if (Arr::has($data, 'items') && is_array($data['items'])) {
            return $data['items'];
        }

        if (Arr::has($data, 'data') && is_array($data['data'])) {
            return $data['data'];
        }

        return $data;
    }

    public function createKnowledge(string $name, ?string $description = null): array
    {
        $response = $this->request()->post($this->path('knowledge_create'), [
            'name' => $name,
            'description' => $description ?? $name,
        ]);
        $response->throw();

        return $response->json() ?? [];
    }

    public function getKnowledge(string $knowledgeId): ?array
    {
        $response = $this->request()->get($this->path('knowledge_get', $knowledgeId));
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();

        $data = $response->json();
        return is_array($data) ? $data : null;
    }

    public function listKnowledgeFiles(string $knowledgeId, array $query = []): array
    {
        $response = $this->request()->get($this->path('knowledge_files', $knowledgeId), $query);
        if ($response->status() === 404) {
            return [];
        }
        $response->throw();

        $data = $response->json();
        return is_array($data) ? $data : [];
    }

    public function searchFilesByFilename(string $filename, int $skip = 0, int $limit = 50): array
    {
        $query = ['filename' => $filename];
        if ($skip > 0) {
            $query['skip'] = $skip;
        }
        if ($limit > 0) {
            $query['limit'] = $limit;
        }

        $response = $this->request()->get($this->path('file_search'), $query);
        if ($response->status() === 404) {
            return [];
        }
        $response->throw();

        $data = $response->json();
        return is_array($data) ? $data : [];
    }

    public function listModels(): array
    {
        $response = $this->request()->get($this->path('model_list'));
        $response->throw();

        $data = $response->json();

        if (!is_array($data)) {
            return [];
        }

        if (Arr::has($data, 'items') && is_array($data['items'])) {
            return $data['items'];
        }

        if (Arr::has($data, 'data') && is_array($data['data'])) {
            return $data['data'];
        }

        return $data;
    }

    public function getModel(string $modelId): ?array
    {
        $response = $this->request()->get($this->path('model_get'), [
            'id' => $modelId,
        ]);

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $data = $response->json();
        return is_array($data) ? $data : null;
    }

    public function createModel(array $payload): array
    {
        $response = $this->request()->post($this->path('model_create'), $payload);
        $response->throw();

        return $response->json() ?? [];
    }

    public function updateModel(array $payload): array
    {
        $response = $this->request()->post($this->path('model_update'), $payload);
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

    public function updateKnowledgeFile(string $knowledgeId, string $fileId): array
    {
        $response = $this->request()->post($this->path('knowledge_file_update', $knowledgeId), [
            'file_id' => $fileId,
        ]);

        $response->throw();

        return $response->json() ?? [];
    }

    public function removeKnowledgeFile(string $knowledgeId, string $fileId, bool $deleteFile = true): array
    {
        $query = ['delete_file' => $deleteFile ? 'true' : 'false'];

        $response = $this->request()
            ->withOptions(['query' => $query])
            ->post($this->path('knowledge_file_remove', $knowledgeId), [
                'file_id' => $fileId,
            ]);

        $response->throw();

        return $response->json() ?? [];
    }

    public function deleteFile(string $fileId): void
    {
        $response = $this->request()->delete($this->path('file_delete', $fileId));
        if ($response->status() === 404) {
            return;
        }
        $response->throw();
    }

    public function updateFileContent(string $fileId, string $content): array
    {
        $response = $this->request()->post($this->path('file_content_update', $fileId), [
            'content' => $content,
        ]);

        $response->throw();

        return $response->json() ?? [];
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
        $baseUrl = (string) config('bookstack-openwebui.openwebui.base_url');
        $apiKey = (string) config('bookstack-openwebui.openwebui.api_key');
        $timeout = (int) config('bookstack-openwebui.openwebui.timeout');
        $verify = (bool) config('bookstack-openwebui.openwebui.verify_tls');

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
