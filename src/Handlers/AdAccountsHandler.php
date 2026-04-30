<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AdAccountsHandler
{
    public function __construct(private readonly PDO $pdo) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        try {
            $body        = (array) ($req->getParsedBody() ?? []);
            $adAccountId = trim(preg_replace('/^act_/', '', $body['ad_account_id'] ?? '') ?? '');
            $label       = trim($body['label'] ?? '');
            $agency      = trim($body['agency'] ?? '');
            $status      = trim($body['status'] ?? 'active');

            if (!$adAccountId) {
                $res->getBody()->write(json_encode(['error' => 'ad_account_id e obrigatorio.']));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            if (!$label) {
                $res->getBody()->write(json_encode(['error' => 'label e obrigatorio.']));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $this->pdo->prepare("
                INSERT INTO ad_accounts (label, ad_account_id, status, agency)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE label=VALUES(label), status=VALUES(status), agency=VALUES(agency)
            ")->execute([$label, $adAccountId, $status, $agency]);

            $res->getBody()->write(json_encode([
                'ok'         => true,
                'ad_account' => ['label' => $label, 'ad_account_id' => $adAccountId, 'status' => $status, 'agency' => $agency],
            ]));
            return $res->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $res->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
