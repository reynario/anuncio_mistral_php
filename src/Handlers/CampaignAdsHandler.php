<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\MetaApiService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CampaignAdsHandler
{
    public function __construct(private readonly MetaApiService $meta) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        try {
            $campaignId = preg_replace('/[^0-9]/', '', $args['campaignId'] ?? '');
            if (!$campaignId) {
                $res->getBody()->write(json_encode(['error' => 'campaignId invalido.']));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $ads = $this->meta->fetchCampaignAds($campaignId);
            $simplified = array_map(fn ($a) => [
                'id'          => $a['id'] ?? '',
                'name'        => $a['name'] ?? '',
                'status'      => $a['status'] ?? '',
                'image_url'   => $this->extractImageUrl($a['creative'] ?? []),
                'link'        => $this->extractLink($a['creative'] ?? []),
                'instagram_permalink' => $a['creative']['instagram_permalink_url'] ?? '',
            ], $ads);

            $res->getBody()->write(json_encode(['ads' => $simplified]));
            return $res->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $res->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    private function extractImageUrl(array $creative): string
    {
        if (!empty($creative['image_url'])) {
            return $creative['image_url'];
        }
        if (!empty($creative['thumbnail_url'])) {
            return $creative['thumbnail_url'];
        }
        $spec = $creative['object_story_spec'] ?? [];
        return $spec['link_data']['picture']
            ?? $spec['video_data']['image_url']
            ?? $spec['photo_data']['url']
            ?? '';
    }

    private function extractLink(array $creative): string
    {
        $spec = $creative['object_story_spec'] ?? [];
        return $spec['link_data']['link']
            ?? $spec['video_data']['call_to_action']['value']['link']
            ?? $spec['link_data']['call_to_action']['value']['link']
            ?? '';
    }
}
