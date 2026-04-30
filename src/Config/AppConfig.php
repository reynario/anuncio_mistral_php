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
        $this->port               = $_ENV['PORT'] ?? '8787';
        $this->appApiKey          = $_ENV['APP_API_KEY'] ?? '';
        $this->buildVersion       = $_ENV['APP_BUILD_VERSION'] ?? ('php-' . date('YmdHis'));

        $this->metaApiUrl         = $_ENV['META_API_URL'] ?? 'https://graph.facebook.com/v25.0';
        $this->metaAccessToken    = $_ENV['META_ACCESS_TOKEN'] ?? '';
        $this->defaultDestinationUrl = $_ENV['DEFAULT_DESTINATION_URL'] ?? 'https://example.com';

        $this->googleClientId     = $_ENV['GOOGLE_OAUTH_CLIENT_ID'] ?? '';
        $this->googleClientSecret = $_ENV['GOOGLE_OAUTH_CLIENT_SECRET'] ?? '';
        $this->googleRedirectUri  = $_ENV['GOOGLE_OAUTH_REDIRECT_URI'] ?? "http://localhost:{$this->port}/api/google/oauth/callback";
        $this->googleRefreshToken = $_ENV['GOOGLE_OAUTH_REFRESH_TOKEN'] ?? '';

        $this->dropboxAccessToken = $_ENV['DROPBOX_ACCESS_TOKEN'] ?? '';
        $this->dropboxAppKey      = $_ENV['DROPBOX_APP_KEY'] ?? '';
        $this->dropboxAppSecret   = $_ENV['DROPBOX_APP_SECRET'] ?? '';
        $this->dropboxRedirectUri = $_ENV['DROPBOX_OAUTH_REDIRECT_URI'] ?? "http://localhost:{$this->port}/api/dropbox/oauth/callback";
        $this->dropboxRefreshToken = $_ENV['DROPBOX_REFRESH_TOKEN'] ?? '';

        $this->dbHost     = $_ENV['DB_HOST'] ?? 'localhost';
        $this->dbPort     = (int) ($_ENV['DB_PORT'] ?? 3306);
        $this->dbUser     = $_ENV['DB_USER'] ?? '';
        $this->dbPassword = $_ENV['DB_PASSWORD'] ?? '';
        $this->dbName     = $_ENV['DB_NAME'] ?? 'anuncios_meta_mistral';
    }
}
