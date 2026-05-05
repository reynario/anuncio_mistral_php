<?php

declare(strict_types=1);

use App\App\Container;
use App\Database\DatabaseMigrator;
use App\Handlers\AdAccountsHandler;
use App\Handlers\CampaignStatusHandler;
use App\Handlers\DropboxOAuthHandler;
use App\Handlers\GeosHandler;
use App\Handlers\GoogleOAuthHandler;
use App\Handlers\MetaGeoHandler;
use App\Handlers\PagesEnrichHandler;
use App\Handlers\PreviewFolderHandler;
use App\Handlers\ReportHandler;
use App\Handlers\RunFolderJobHandler;
use App\Handlers\SetupOptionsHandler;
use App\Handlers\VersionHandler;
use App\Middleware\AuthMiddleware;
use DI\Bridge\Slim\Bridge;
use Dotenv\Dotenv;
use Slim\Routing\RouteCollectorProxy;

require __DIR__ . '/vendor/autoload.php';

// Convert PHP warnings/notices to exceptions so Slim can render them as JSON
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// Catch fatal errors (parse errors, memory exhaustion) and emit JSON instead of empty body
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'error' => sprintf('Fatal: %s in %s:%d', $err['message'], $err['file'], $err['line']),
        ]);
    }
});

// Load environment
if (file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->load();
}

// Build DI container
$builder = new \DI\ContainerBuilder();
$builder->addDefinitions(Container::definitions());
$container = $builder->build();

// Run DB migrations
try {
    (new DatabaseMigrator())->migrate($container->get(PDO::class));
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database unavailable: ' . $e->getMessage()]);
    exit;
}

// Build Slim app
$app = Bridge::create($container);
$app->addBodyParsingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler(
    function (\Psr\Http\Message\ServerRequestInterface $req, \Throwable $e) use ($app) {
        $res = $app->getResponseFactory()->createResponse(500);
        $res->getBody()->write(json_encode([
            'error' => $e->getMessage(),
            'type'  => get_class($e),
            'file'  => $e->getFile() . ':' . $e->getLine(),
        ]));
        return $res->withHeader('Content-Type', 'application/json');
    }
);

// ── Public routes ──────────────────────────────────────────────────────────
$app->get('/api/version', VersionHandler::class);

$app->get('/api/google/oauth/callback', function ($req, $res) use ($container) {
    return $container->get(GoogleOAuthHandler::class)->callback($req, $res);
});
$app->get('/api/dropbox/oauth/callback', function ($req, $res) use ($container) {
    return $container->get(DropboxOAuthHandler::class)->callback($req, $res);
});
$app->get('/api/public/report', ReportHandler::class);

// ── Protected routes ───────────────────────────────────────────────────────
$app->group('', function (RouteCollectorProxy $group) use ($container) {

    $group->get('/api/google/oauth/start', function ($req, $res) use ($container) {
        return $container->get(GoogleOAuthHandler::class)->start($req, $res);
    });
    $group->get('/api/dropbox/oauth/start', function ($req, $res) use ($container) {
        return $container->get(DropboxOAuthHandler::class)->start($req, $res);
    });

    $group->get('/api/setup/options',            SetupOptionsHandler::class);
    $group->get('/api/report/client-insights',   ReportHandler::class);

    $group->post('/api/pages/enrich',            PagesEnrichHandler::class);

    $group->get('/api/meta/geo/cities',  function ($req, $res) use ($container) {
        return $container->get(MetaGeoHandler::class)->cities($req, $res);
    });
    $group->get('/api/meta/geo/regions', function ($req, $res) use ($container) {
        return $container->get(MetaGeoHandler::class)->regions($req, $res);
    });

    $group->post('/api/ad-accounts/upsert',      AdAccountsHandler::class);
    $group->post('/api/geos/upsert',             GeosHandler::class);

    $group->get('/api/campaigns/status/{jobId}', CampaignStatusHandler::class);

    $group->post('/api/preview-folder',          PreviewFolderHandler::class);
    $group->post('/api/run-folder-job',          RunFolderJobHandler::class);

})->add(AuthMiddleware::class);

$app->run();
