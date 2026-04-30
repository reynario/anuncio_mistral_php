<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SetupOptionsHandler
{
    public function __construct(private readonly PDO $pdo) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $adAccounts = $this->pdo->query(
            "SELECT label, ad_account_id, status, agency FROM ad_accounts WHERE status != 'inactive' ORDER BY label"
        )->fetchAll();

        $pages = $this->pdo->query(
            "SELECT page_id, cliente, page_name, ad_account_id,
                    primary_whatsapp_phone_number, primary_whatsapp_phone_id,
                    whatsapp_business_account_id, instagram_actor_id,
                    instagram_username, lead_form_id, status
             FROM pages WHERE status != 'inactive' ORDER BY cliente, page_name"
        )->fetchAll();

        $geos = $this->pdo->query(
            "SELECT geo_key, name, type, countries, regions_json, cities_json, radius_km
             FROM geo_targets ORDER BY name"
        )->fetchAll();

        $res->getBody()->write(json_encode([
            'ad_accounts' => $adAccounts,
            'pages'       => $pages,
            'geos'        => $geos,
        ]));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
