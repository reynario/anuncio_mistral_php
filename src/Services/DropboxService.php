<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use GuzzleHttp\Client;

class DropboxService
{
    private const TOKEN_URL = 'https://api.dropbox.com/oauth2/token';
    private const AUTH_URL  = 'https://www.dropbox.com/oauth2/authorize';
    private const CACHE_KEY = 'dropbox_access_token';

    private ?string $cachedToken     = null;
    private ?int    $cachedExpiresAt = null;

    public function __construct(
        private readonly AppConfig $config,
        private readonly Client $http,
    ) {}

    public function getAuthUrl(): string
    {
        $params = http_build_query([
            'client_id'     => $this->config->dropboxAppKey,
            'response_type' => 'code',
            'redirect_uri'  => $this->config->dropboxRedirectUri,
            'token_access_type' => 'offline',
        ]);
        return self::AUTH_URL . '?' . $params;
    }

    public function exchangeCode(string $code): array
    {
        $response = $this->http->post(self::TOKEN_URL, [
            'form_params' => [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $this->config->dropboxRedirectUri,
                'client_id'    => $this->config->dropboxAppKey,
                'client_secret' => $this->config->dropboxAppSecret,
            ],
        ]);
        return json_decode((string) $response->getBody(), true);
    }

    public function getAccessToken(): string
    {
        $now = time();

        if ($this->cachedToken && $this->cachedExpiresAt && $now < $this->cachedExpiresAt - 60) {
            return $this->cachedToken;
        }

        if (function_exists('apcu_fetch')) {
            $token = apcu_fetch(self::CACHE_KEY, $success);
            if ($success && $token) {
                $this->cachedToken = $token;
                return $token;
            }
        }

        // Prefer refresh token; fall back to static token
        if ($this->config->dropboxRefreshToken) {
            $response = $this->http->post(self::TOKEN_URL, [
                'form_params' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $this->config->dropboxRefreshToken,
                    'client_id'     => $this->config->dropboxAppKey,
                    'client_secret' => $this->config->dropboxAppSecret,
                ],
            ]);
            $data        = json_decode((string) $response->getBody(), true);
            $accessToken = $data['access_token'] ?? '';
            $expiresIn   = (int) ($data['expires_in'] ?? 14400);
        } elseif ($this->config->dropboxAccessToken) {
            return $this->config->dropboxAccessToken;
        } else {
            throw new \RuntimeException('Nenhum token Dropbox configurado (DROPBOX_REFRESH_TOKEN ou DROPBOX_ACCESS_TOKEN).');
        }

        if (!$accessToken) {
            throw new \RuntimeException('Nao foi possivel obter access token do Dropbox.');
        }

        $this->cachedToken     = $accessToken;
        $this->cachedExpiresAt = $now + $expiresIn;

        if (function_exists('apcu_store')) {
            apcu_store(self::CACHE_KEY, $accessToken, max(1, $expiresIn - 60));
        }

        return $accessToken;
    }

    public function listFolderUrl(string $folderUrl): array
    {
        $token = $this->getAccessToken();

        $metaResponse = $this->http->post('https://api.dropboxapi.com/2/sharing/get_shared_link_metadata', [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode(['url' => $folderUrl]),
        ]);
        $meta = json_decode((string) $metaResponse->getBody(), true);

        if ($metaResponse->getStatusCode() >= 400) {
            throw new \RuntimeException('Erro ao obter metadata do Dropbox: ' . ($meta['error_summary'] ?? json_encode($meta)));
        }

        // Single file shared link
        if (($meta['.tag'] ?? '') === 'file') {
            return [[
                'id'        => $meta['id'],
                'name'      => $meta['name'],
                'mimeType'  => $this->guessMime($meta['name']),
                'source'    => 'dropbox',
                'sharedUrl' => $folderUrl,
            ]];
        }

        if (($meta['.tag'] ?? '') !== 'folder') {
            throw new \InvalidArgumentException('URL do Dropbox invalida. Use a URL de uma pasta ou de um arquivo compartilhado.');
        }

        $folderId  = $meta['id'];
        $listResponse = $this->http->post('https://api.dropboxapi.com/2/files/list_folder', [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode(['path' => $folderId, 'recursive' => false]),
        ]);
        $listData = json_decode((string) $listResponse->getBody(), true);

        if ($listResponse->getStatusCode() >= 400) {
            throw new \RuntimeException('Erro ao listar pasta do Dropbox: ' . ($listData['error_summary'] ?? json_encode($listData)));
        }

        return array_map(fn ($f) => [
            'id'             => $f['id'],
            'name'           => $f['name'],
            'mimeType'       => $this->guessMime($f['name']),
            'source'         => 'dropbox',
            'folderSharedUrl' => $folderUrl,
            'pathInFolder'   => '/' . $f['name'],
        ], array_filter($listData['entries'] ?? [], fn ($e) => ($e['.tag'] ?? '') === 'file'));
    }

    public function downloadFile(array $file): string
    {
        $token = $this->getAccessToken();

        if (!empty($file['sharedUrl']) || !empty($file['folderSharedUrl'])) {
            $arg = $file['sharedUrl']
                ? ['url' => $file['sharedUrl']]
                : ['url' => $file['folderSharedUrl'], 'path' => $file['pathInFolder']];

            $response = $this->http->post('https://content.dropboxapi.com/2/sharing/get_shared_link_file', [
                'headers' => [
                    'Authorization'   => "Bearer {$token}",
                    'Dropbox-API-Arg' => json_encode($arg),
                ],
            ]);
            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException('Falha no download via link compartilhado do Dropbox (' . ($file['name'] ?? '') . ').');
            }
            return (string) $response->getBody();
        }

        $response = $this->http->post('https://content.dropboxapi.com/2/files/download', [
            'headers' => [
                'Authorization'   => "Bearer {$token}",
                'Dropbox-API-Arg' => json_encode(['path' => $file['id']]),
            ],
        ]);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Falha no download do Dropbox (' . ($file['name'] ?? $file['id']) . ').');
        }
        return (string) $response->getBody();
    }

    private function guessMime(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'webp' => 'image/webp', 'gif' => 'image/gif',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska', 'webm' => 'video/webm',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
}
