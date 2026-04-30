<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\JobQueueService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RunFolderJobHandler
{
    private const REQUIRED = ['campaign_type', 'ads', 'client_name', 'investment_type', 'investment_value'];

    public function __construct(private readonly JobQueueService $jobs) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        try {
            $payload = (array) ($req->getParsedBody() ?? []);

            $missing = array_filter(self::REQUIRED, function ($k) use ($payload) {
                $v = $payload[$k] ?? null;
                return $v === null || $v === '' || (is_string($v) && trim($v) === '');
            });

            if (!isset($payload['ad_account_id']) || !isset($payload['page_id'])) {
                $missing[] = 'ad_account_id+page_id';
            }

            if (!empty($missing)) {
                $res->getBody()->write(json_encode(['error' => 'Campos obrigatorios faltando: ' . implode(', ', $missing)]));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            if (!is_array($payload['ads']) || empty($payload['ads'])) {
                $res->getBody()->write(json_encode(['error' => 'ads deve ser um array com ao menos 1 item.']));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $campaignType = strtolower(trim($payload['campaign_type'] ?? ''));
            if (!in_array($campaignType, ['whatsapp', 'pixel'], true)) {
                $res->getBody()->write(json_encode(['error' => 'campaign_type invalido. Use apenas whatsapp ou pixel.']));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $jobId = $this->jobs->generateId();
            $this->jobs->createJob($jobId, $payload);
            $this->jobs->pushLog($jobId, 'info', 'Job criado — aguardando worker.');

            $res->getBody()->write(json_encode(['jobId' => $jobId]));
            return $res->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $res->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
