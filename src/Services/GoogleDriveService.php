<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use Google\Auth\OAuth2;
use GuzzleHttp\Client;

class GoogleDriveService
{
    private const SCOPE        = 'https://www.googleapis.com/auth/drive.readonly';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const CACHE_KEY    = 'google_access_token';

    private ?string $cachedToken     = null;
    private ?int    $cachedExpiresAt = null;

    public function __construct(
        private readonly AppConfig $config,
        private readonly Client $http,
    ) {}

    public function getAuthUrl(): string
    {
        $oauth2 = $this->makeOAuth2();
        return $oauth2->buildFullAuthorizationUri([
            'prompt'      => 'consent',
            'access_type' => 'offline',
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $oauth2 = $this->makeOAuth2();
        $oauth2->setCode($code);
        return $oauth2->fetchAuthToken();
    }

    public function getAccessToken(): string
    {
        $now = time();

        // Static cache for CLI worker (single long-lived process)
        if ($this->cachedToken && $this->cachedExpiresAt && $now < $this->cachedExpiresAt - 60) {
            return $this->cachedToken;
        }

        // APCu cache for FPM (shared across worker processes in same container)
        if (function_exists('apcu_fetch')) {
            $token = apcu_fetch(self::CACHE_KEY, $success);
            if ($success && $token) {
                $this->cachedToken = $token;
                return $token;
            }
        }

        $oauth2 = $this->makeOAuth2();
        $oauth2->setRefreshToken($this->config->googleRefreshToken);
        $result = $oauth2->fetchAuthToken();

        $accessToken = $result['access_token'] ?? '';
        $expiresIn   = (int) ($result['expires_in'] ?? 3600);

        if (!$accessToken) {
            throw new \RuntimeException('Nao foi possivel obter access token do Google.');
        }

        $this->cachedToken     = $accessToken;
        $this->cachedExpiresAt = $now + $expiresIn;

        if (function_exists('apcu_store')) {
            apcu_store(self::CACHE_KEY, $accessToken, max(1, $expiresIn - 60));
        }

        return $accessToken;
    }

    public function listFolder(string $folderId): array
    {
        $token    = $this->getAccessToken();
        $response = $this->http->get('https://www.googleapis.com/drive/v3/files', [
            'headers' => ['Authorization' => "Bearer {$token}"],
            'query'   => [
                'q'                         => "'{$folderId}' in parents and trashed=false",
                'fields'                    => 'files(id,name,mimeType,webViewLink)',
                'pageSize'                  => '1000',
                'supportsAllDrives'         => 'true',
                'includeItemsFromAllDrives' => 'true',
            ],
        ]);
        $data = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Erro ao listar pasta do Drive: ' . json_encode($data));
        }
        return $data['files'] ?? [];
    }

    public function downloadFile(string $fileId): string
    {
        $token    = $this->getAccessToken();
        $response = $this->http->get("https://www.googleapis.com/drive/v3/files/{$fileId}", [
            'headers' => ['Authorization' => "Bearer {$token}"],
            'query'   => ['alt' => 'media'],
        ]);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException("Falha no download do Drive ({$fileId}).");
        }
        return (string) $response->getBody();
    }

    private function makeOAuth2(): OAuth2
    {
        return new OAuth2([
            'clientId'             => $this->config->googleClientId,
            'clientSecret'         => $this->config->googleClientSecret,
            'redirectUri'          => $this->config->googleRedirectUri,
            'authorizationUri'     => self::AUTH_URL,
            'tokenCredentialUri'   => self::TOKEN_URL,
            'scope'                => self::SCOPE,
        ]);
    }
}
