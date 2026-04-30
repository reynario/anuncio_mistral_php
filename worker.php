<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Campaign\AdsetBuilder;
use App\Campaign\CampaignCreator;
use App\Campaign\CreativeFactory;
use App\Campaign\MediaUploader;
use App\Config\AppConfig;
use App\Database\DatabaseMigrator;
use App\Services\DropboxService;
use App\Services\GoogleDriveService;
use App\Services\JobQueueService;
use App\Services\MediaGroupingService;
use App\Services\MetaApiService;
use GuzzleHttp\Client;
use Dotenv\Dotenv;

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->load();
}

$config   = new AppConfig();
$http     = new Client(['timeout' => 120, 'connect_timeout' => 10, 'http_errors' => false]);

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->dbHost, $config->dbPort, $config->dbName);
$pdo = new PDO($dsn, $config->dbUser, $config->dbPassword, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

(new DatabaseMigrator())->migrate($pdo);

$jobs     = new JobQueueService($pdo);
$meta     = new MetaApiService($config, $http);
$drive    = new GoogleDriveService($config, $http);
$dropbox  = new DropboxService($config, $http);
$grouper  = new MediaGroupingService();
$uploader = new MediaUploader($meta);
$factory  = new CreativeFactory($meta, $config);
$adsets   = new AdsetBuilder($meta);

$creator = new CampaignCreator($meta, $drive, $dropbox, $jobs, $uploader, $factory, $adsets, $grouper, $pdo);

// On startup, reset jobs stuck in 'running' from a previous crashed worker
$jobs->resetStuckJobs();

echo "[worker] Iniciado. Aguardando jobs...\n";

while (true) {
    try {
        $job = $jobs->claimNextPendingJob();
        if ($job) {
            echo "[worker] Processando job {$job['id']}\n";
            $creator->run($job['id'], $job['payload']);
            echo "[worker] Job {$job['id']} finalizado\n";
        }
    } catch (\Throwable $e) {
        echo "[worker] Erro inesperado: " . $e->getMessage() . "\n";
    }

    sleep(2);
}
