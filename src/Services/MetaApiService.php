<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MetaApiService
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly Client $http,
    ) {}

    /**
     * Core Meta Graph API request — equivalent to graphRequest() in Node.
     *
     * @param array<string,mixed>       $params
     * @param array<string,mixed>|null  $formFields  multipart fields (key => value or key => ['name','contents','filename'])
     */
    public function request(string $path, string $method = 'GET', array $params = [], ?array $formFields = null): array
    {
        if (!$this->config->metaAccessToken) {
            throw new \RuntimeException('META_ACCESS_TOKEN nao configurado.');
        }

        $url     = rtrim($this->config->metaApiUrl, '/') . $path;
        $method  = strtoupper($method);
        $options = [];

        if ($method === 'GET') {
            $query = ['access_token' => $this->config->metaAccessToken];
            foreach ($params as $k => $v) {
                $query[$k] = is_string($v) ? $v : json_encode($v);
            }
            $options['query'] = $query;
        } elseif ($formFields !== null) {
            $multipart = [['name' => 'access_token', 'contents' => $this->config->metaAccessToken]];
            foreach ($formFields as $name => $value) {
                if (is_array($value) && isset($value['contents'])) {
                    $multipart[] = $value;
                } else {
                    $multipart[] = ['name' => $name, 'contents' => is_string($value) ? $value : json_encode($value)];
                }
            }
            foreach ($params as $k => $v) {
                $multipart[] = ['name' => $k, 'contents' => is_string($v) ? $v : json_encode($v)];
            }
            $options['multipart'] = $multipart;
        } else {
            $body = ['access_token' => $this->config->metaAccessToken];
            foreach ($params as $k => $v) {
                $body[$k] = is_string($v) ? $v : json_encode($v);
            }
            $options['form_params'] = $body;
        }

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Meta Graph erro de conexao: ' . $e->getMessage());
        }

        $raw  = (string) $response->getBody();
        $data = $raw ? json_decode($raw, true) : [];

        if (json_last_error() !== JSON_ERROR_NONE) {
            $preview = substr(str_replace(["\n", "\r"], ' ', $raw), 0, 180);
            throw new \RuntimeException("Meta Graph retornou resposta nao-JSON (HTTP {$response->getStatusCode()}): {$preview}");
        }

        if ($response->getStatusCode() >= 400 || isset($data['error'])) {
            throw new \RuntimeException('Meta Graph erro: ' . json_encode($data));
        }

        return $data;
    }

    public function requestSafe(string $path, array $params = []): ?array
    {
        try {
            return $this->request($path, 'GET', $params);
        } catch (\Throwable) {
            return null;
        }
    }

    public function searchGeoLocations(string $locationType, array $params = []): array
    {
        $p = array_merge(['type' => 'adgeolocation', 'location_types' => [$locationType]], $params);
        $data = $this->request('/search', 'GET', $p);
        return $data['data'] ?? [];
    }

    public function fetchPageInfo(string $pageId): ?array
    {
        $fields = implode(',', [
            'id', 'name',
            'whatsapp_business_account{id,message_templates_namespace,phone_numbers{id,display_phone_number}}',
            'connected_instagram_accounts{id,username}',
        ]);
        return $this->requestSafe("/{$pageId}", ['fields' => $fields]);
    }

    public function fetchLeadForms(string $pageId): ?array
    {
        return $this->requestSafe("/{$pageId}/leadgen_forms", [
            'fields' => 'id,name,status',
            'limit'  => 10,
        ]);
    }

    public function fetchAdAccountInstagramActors(string $adAccount): ?array
    {
        $data = $this->requestSafe("/act_{$adAccount}", ['fields' => 'instagram_accounts{id,username}']);
        return $data['instagram_accounts']['data'] ?? null;
    }
}
