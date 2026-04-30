<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\MetaApiService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MetaGeoHandler
{
    public function __construct(private readonly MetaApiService $meta) {}

    public function cities(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        try {
            $qs          = $req->getQueryParams();
            $q           = trim($qs['q'] ?? '');
            $countryCode = strtoupper(trim($qs['country_code'] ?? 'BR'));
            $limit       = max(1, min(50, (int) ($qs['limit'] ?? 20)));

            if (!$q) {
                $res->getBody()->write(json_encode(['error' => 'Parametro q e obrigatorio.']));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $items = $this->meta->searchGeoLocations('city', [
                'q'            => $q,
                'country_code' => $countryCode,
                'limit'        => $limit,
            ]);

            $cities = array_map(fn ($item) => [
                'key'            => $item['key'] ?? '',
                'name'           => $item['name'] ?? '',
                'region'         => $item['region'] ?? '',
                'region_id'      => isset($item['region_id']) ? (string) $item['region_id'] : '',
                'country_code'   => $item['country_code'] ?? $countryCode,
                'supports_region' => $item['supports_region'] ?? false,
            ], $items);

            $res->getBody()->write(json_encode(['q' => $q, 'country_code' => $countryCode, 'cities' => $cities]));
            return $res->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $res->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function regions(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        try {
            $qs          = $req->getQueryParams();
            $q           = trim($qs['q'] ?? '');
            $countryCode = strtoupper(trim($qs['country_code'] ?? 'BR'));
            $limit       = max(1, min(100, (int) ($qs['limit'] ?? 50)));

            $params = ['country_code' => $countryCode, 'limit' => $limit];
            if ($q) {
                $params['q'] = $q;
            }

            $items = $this->meta->searchGeoLocations('region', $params);

            $regions = array_map(fn ($item) => [
                'key'          => $item['key'] ?? '',
                'name'         => $item['name'] ?? '',
                'country_code' => $item['country_code'] ?? $countryCode,
            ], $items);

            $res->getBody()->write(json_encode(['q' => $q, 'country_code' => $countryCode, 'regions' => $regions]));
            return $res->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $res->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
