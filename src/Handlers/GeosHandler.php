<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class GeosHandler
{
    public function __construct(private readonly PDO $pdo) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        try {
            $body = (array) ($req->getParsedBody() ?? []);
            $row  = $this->normalize($body);

            if (!$row['geo_key']) {
                $res->getBody()->write(json_encode(['error' => 'geo_key e obrigatorio.']));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $this->pdo->prepare("
                INSERT INTO geo_targets (geo_key, name, type, countries, regions_json, cities_json, radius_km)
                VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  name=VALUES(name), type=VALUES(type), countries=VALUES(countries),
                  regions_json=VALUES(regions_json), cities_json=VALUES(cities_json),
                  radius_km=VALUES(radius_km)
            ")->execute([
                $row['geo_key'], $row['name'], $row['type'],
                $row['countries'], $row['regions_json'], $row['cities_json'], $row['radius_km'],
            ]);

            $res->getBody()->write(json_encode(['ok' => true, 'geo' => $row]));
            return $res->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $res->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    private function normalize(array $raw): array
    {
        $countries = implode(',', array_filter(array_map(
            fn ($c) => strtoupper(trim($c)),
            explode(',', $raw['countries'] ?? 'BR')
        )));

        $cities  = is_array($raw['cities'] ?? null)  ? ($raw['cities'])  : json_decode($raw['cities_json']  ?? '[]', true) ?? [];
        $regions = is_array($raw['regions'] ?? null) ? ($raw['regions']) : json_decode($raw['regions_json'] ?? '[]', true) ?? [];

        $type = $raw['type'] ?? (
            (count($cities) && count($regions))  ? 'mixed'  :
            (count($cities)  ? 'city'   :
            (count($regions) ? 'region' : 'country'))
        );

        return [
            'geo_key'      => trim($raw['geo_key'] ?? ''),
            'name'         => trim($raw['name'] ?? $raw['geo_key'] ?? ''),
            'type'         => $type,
            'countries'    => $countries ?: 'BR',
            'regions_json' => json_encode($regions),
            'cities_json'  => json_encode($cities),
            'radius_km'    => trim($raw['radius_km'] ?? ''),
        ];
    }
}
