<?php

declare(strict_types=1);

namespace App\App;

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
use PDO;
use PDOException;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use function DI\autowire;
use function DI\create;
use function DI\get;

class Container
{
    public static function definitions(): array
    {
        return [
            AppConfig::class => create(AppConfig::class),

            ResponseFactoryInterface::class => create(ResponseFactory::class),

            PDO::class => function (AppConfig $config): PDO {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $config->dbHost,
                    $config->dbPort,
                    $config->dbName
                );
                try {
                    $pdo = new PDO($dsn, $config->dbUser, $config->dbPassword, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    return $pdo;
                } catch (PDOException $e) {
                    throw new \RuntimeException('Falha ao conectar ao banco de dados: ' . $e->getMessage());
                }
            },

            Client::class => function (): Client {
                return new Client([
                    'timeout'         => 60,
                    'connect_timeout' => 10,
                    'http_errors'     => false,
                ]);
            },

            MetaApiService::class    => autowire(MetaApiService::class),
            GoogleDriveService::class => autowire(GoogleDriveService::class),
            DropboxService::class    => autowire(DropboxService::class),
            JobQueueService::class   => autowire(JobQueueService::class),
            MediaGroupingService::class => create(MediaGroupingService::class),

            MediaUploader::class   => autowire(MediaUploader::class),
            CreativeFactory::class => autowire(CreativeFactory::class),
            AdsetBuilder::class    => autowire(AdsetBuilder::class),
            CampaignCreator::class => autowire(CampaignCreator::class),
        ];
    }
}
