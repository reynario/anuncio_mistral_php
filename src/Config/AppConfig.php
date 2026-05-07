<?php

declare(strict_types=1);

namespace App\Config;

class AppConfig
{
    public readonly string $port;
    public readonly string $appApiKey;
    public readonly string $buildVersion;

    public readonly string $metaApiUrl;
    public readonly string $metaAccessToken;
    public readonly string $defaultDestinationUrl;

    public readonly string $googleClientId;
    public readonly string $googleClientSecret;
    public readonly string $googleRedirectUri;
    public readonly string $googleRefreshToken;

    public readonly string $dropboxAccessToken;
    public readonly string $dropboxAppKey;
    public readonly string $dropboxAppSecret;
    public readonly string $dropboxRedirectUri;
    public readonly string $dropboxRefreshToken;

    public readonly string $dbHost;
    public readonly int    $dbPort;
    public readonly string $dbUser;
    public readonly string $dbPassword;
    public readonly string $dbName;

    public function __construct()
    {
        $this->port               = self::env('PORT', '8787');
        $this->appApiKey          = self::env('APP_API_KEY');
        $this->buildVersion       = self::env('APP_BUILD_VERSION', 'php-' . date('YmdHis'));

        $this->metaApiUrl         = self::env('META_API_URL', 'https://graph.facebook.com/v25.0');
        $this->metaAccessToken    = self::env('META_ACCESS_TOKEN');
        $this->defaultDestinationUrl = self::env('DEFAULT_DESTINATION_URL', 'https://example.com');

        $this->googleClientId     = self::env('GOOGLE_OAUTH_CLIENT_ID');
        $this->googleClientSecret = self::env('GOOGLE_OAUTH_CLIENT_SECRET');
        $this->googleRedirectUri  = self::env('GOOGLE_OAUTH_REDIRECT_URI', "http://localhost:{$this->port}/api/google/oauth/callback");
        $this->googleRefreshToken = self::env('GOOGLE_OAUTH_REFRESH_TOKEN');

        $this->dropboxAccessToken = self::env('DROPBOX_ACCESS_TOKEN');
        $this->dropboxAppKey      = self::env('DROPBOX_APP_KEY');
        $this->dropboxAppSecret   = self::env('DROPBOX_APP_SECRET');
        $this->dropboxRedirectUri = self::env('DROPBOX_OAUTH_REDIRECT_URI', "http://localhost:{$this->port}/api/dropbox/oauth/callback");
        $this->dropboxRefreshToken = self::env('DROPBOX_REFRESH_TOKEN');

        $this->dbHost     = self::env('DB_HOST', 'localhost');
        $this->dbPort     = (int) self::env('DB_PORT', '3306');
        $this->dbUser     = self::env('DB_USER');
        $this->dbPassword = self::env('DB_PASSWORD');
        $this->dbName     = self::env('DB_NAME', 'anuncios_meta_mistral');
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }
}
