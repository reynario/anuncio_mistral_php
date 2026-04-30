<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\MetaApiService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PagesEnrichHandler
{
    public function __construct(
        private readonly MetaApiService $meta,
        private readonly PDO $pdo,
    ) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        try {
            $body        = (array) ($req->getParsedBody() ?? []);
            $singlePageId = trim($body['page_id'] ?? '');
            $adAccountId  = trim($body['ad_account_id'] ?? '');
            $cliente      = trim($body['cliente'] ?? '');

            if ($singlePageId) {
                $result = $this->enrichPage($singlePageId, $adAccountId, $cliente);
                $res->getBody()->write(json_encode($result));
                return $res->withHeader('Content-Type', 'application/json');
            }

            // Bulk: enrich all pages in DB
            $pages   = $this->pdo->query("SELECT page_id, ad_account_id, cliente FROM pages")->fetchAll();
            $results = [];
            foreach ($pages as $p) {
                try {
                    $results[] = array_merge(
                        $this->enrichPage($p['page_id'], $p['ad_account_id'], $p['cliente']),
                        ['ok' => true]
                    );
                } catch (\Throwable $e) {
                    $results[] = ['page_id' => $p['page_id'], 'ok' => false, 'error' => $e->getMessage()];
                }
            }
            $res->getBody()->write(json_encode(['results' => $results]));
            return $res->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $res->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    private function enrichPage(string $pageId, string $adAccountId, string $cliente): array
    {
        $info = $this->meta->fetchPageInfo($pageId);
        if (!$info) {
            throw new \RuntimeException("Pagina {$pageId} nao encontrada na Meta API.");
        }

        $pageName = $info['name'] ?? '';

        // WhatsApp info
        $wabPhoneNumbers = $info['whatsapp_business_account']['phone_numbers']['data'] ?? [];
        $firstPhone      = $wabPhoneNumbers[0] ?? [];
        $phoneNumber     = $firstPhone['display_phone_number'] ?? '';
        $phoneId         = $firstPhone['id'] ?? '';
        $wabaId          = $info['whatsapp_business_account']['id'] ?? '';

        // Instagram
        $igAccounts  = $info['connected_instagram_accounts']['data'] ?? [];
        $firstIg     = $igAccounts[0] ?? [];
        $igActorId   = $firstIg['id'] ?? '';
        $igUsername  = $firstIg['username'] ?? '';

        // Lead form
        $formsData = $this->meta->fetchLeadForms($pageId);
        $leadFormId = '';
        foreach ($formsData['data'] ?? [] as $form) {
            if (($form['status'] ?? '') === 'ACTIVE') {
                $leadFormId = $form['id'];
                break;
            }
        }

        $this->upsertPage([
            'page_id'                      => $pageId,
            'cliente'                      => $cliente,
            'page_name'                    => $pageName,
            'ad_account_id'                => $adAccountId,
            'primary_whatsapp_phone_number' => $phoneNumber,
            'primary_whatsapp_phone_id'    => $phoneId,
            'whatsapp_business_account_id' => $wabaId,
            'instagram_actor_id'           => $igActorId,
            'instagram_username'           => $igUsername,
            'lead_form_id'                 => $leadFormId,
            'status'                       => 'active',
        ]);

        return ['page' => ['page_id' => $pageId, 'page_name' => $pageName]];
    }

    private function upsertPage(array $row): void
    {
        $this->pdo->prepare("
            INSERT INTO pages
              (page_id, cliente, page_name, ad_account_id, primary_whatsapp_phone_number,
               primary_whatsapp_phone_id, whatsapp_business_account_id,
               instagram_actor_id, instagram_username, lead_form_id, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              cliente=VALUES(cliente), page_name=VALUES(page_name),
              ad_account_id=VALUES(ad_account_id),
              primary_whatsapp_phone_number=VALUES(primary_whatsapp_phone_number),
              primary_whatsapp_phone_id=VALUES(primary_whatsapp_phone_id),
              whatsapp_business_account_id=VALUES(whatsapp_business_account_id),
              instagram_actor_id=VALUES(instagram_actor_id),
              instagram_username=VALUES(instagram_username),
              lead_form_id=VALUES(lead_form_id), status=VALUES(status)
        ")->execute(array_values($row));
    }
}
