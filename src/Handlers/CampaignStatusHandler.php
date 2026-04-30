<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\JobQueueService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CampaignStatusHandler
{
    public function __construct(private readonly JobQueueService $jobs) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $jobId = $args['jobId'] ?? '';
        $job   = $this->jobs->getJob($jobId);

        if (!$job) {
            $res->getBody()->write(json_encode(['error' => 'Job nao encontrado.']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $res->getBody()->write(json_encode($job));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
