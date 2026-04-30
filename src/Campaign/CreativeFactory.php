<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Config\AppConfig;
use App\Services\MetaApiService;

class CreativeFactory
{
    public function __construct(
        private readonly MetaApiService $meta,
        private readonly AppConfig $config,
    ) {}

    public function createFeed(string $adAccount, array $ad, string $pageId, string $campaignType, string $imageHash, ?string $instagramActorId, string $jobId): string
    {
        $link      = $this->link($campaignType);
        $ctaType   = $this->cta($campaignType);
        $callToAction = $campaignType === 'whatsapp'
            ? ['type' => $ctaType, 'value' => ['app_destination' => 'WHATSAPP']]
            : ['type' => $ctaType, 'value' => ['link' => $link]];

        $payload = [
            'name'               => "{$ad['name']} - creative-feed",
            'object_story_spec'  => array_filter([
                'page_id'             => $pageId,
                'instagram_actor_id'  => $instagramActorId ?: null,
                'link_data'           => [
                    'image_hash'   => $imageHash,
                    'link'         => $link,
                    'message'      => $ad['text'] ?? '',
                    'name'         => $ad['title'] ?? '',
                    'description'  => $ad['desc'] ?? '',
                    'call_to_action' => $callToAction,
                ],
            ]),
        ];

        return $this->createWithIgFallback($adAccount, $payload, $instagramActorId);
    }

    public function createFeedStories(string $adAccount, array $ad, string $pageId, string $campaignType, string $feedHash, string $storiesHash, ?string $instagramActorId): string
    {
        $link    = $this->link($campaignType);
        $ctaType = $this->cta($campaignType);

        $payload = [
            'name'              => "{$ad['name']} - creative-feed-stories",
            'object_story_spec' => array_filter([
                'page_id'            => $pageId,
                'instagram_actor_id' => $instagramActorId ?: null,
            ]),
            'asset_feed_spec'   => [
                'ad_formats'    => ['SINGLE_IMAGE'],
                'images'        => [['hash' => $feedHash], ['hash' => $storiesHash]],
                'bodies'        => [['text' => $ad['text'] ?? '']],
                'titles'        => [['text' => $ad['title'] ?? '']],
                'descriptions'  => [['text' => $ad['desc'] ?? '']],
                'call_to_action_types' => [$ctaType],
                'link_urls'     => [['website_url' => $link]],
                'asset_customization_rules' => [
                    [
                        'customization_spec' => [
                            'publisher_platforms' => ['facebook', 'instagram'],
                            'facebook_positions'  => ['feed'],
                            'instagram_positions' => ['stream'],
                        ],
                        'image_label' => ['name' => 'feed'],
                    ],
                    [
                        'customization_spec' => [
                            'publisher_platforms' => ['facebook', 'instagram'],
                            'facebook_positions'  => ['story'],
                            'instagram_positions' => ['story'],
                        ],
                        'image_label' => ['name' => 'stories'],
                    ],
                    [
                        'customization_spec' => [
                            'publisher_platforms' => ['facebook', 'instagram'],
                            'facebook_positions'  => ['facebook_reels'],
                            'instagram_positions' => ['reels'],
                        ],
                        'image_label' => ['name' => 'stories'],
                    ],
                ],
            ],
        ];

        return $this->createWithIgFallback($adAccount, $payload, $instagramActorId);
    }

    public function createVideo(string $adAccount, array $ad, string $pageId, string $campaignType, string $videoId, ?string $thumbnailUrl, ?string $instagramActorId): string
    {
        $isWhatsapp = $campaignType === 'whatsapp';
        $link       = $isWhatsapp ? 'https://api.whatsapp.com/send' : $this->link($campaignType);
        $ctaType    = $this->cta($campaignType);
        $ctaValue   = $isWhatsapp ? ['app_destination' => 'WHATSAPP'] : ['link' => $link];

        $videoData = array_filter([
            'video_id'       => $videoId,
            'message'        => $ad['text'] ?? '',
            'title'          => $ad['title'] ?? '',
            'image_url'      => $thumbnailUrl,
            'call_to_action' => ['type' => $ctaType, 'value' => $ctaValue],
        ]);

        $payload = [
            'name'              => "{$ad['name']} - creative-video",
            'object_story_spec' => array_filter([
                'page_id'            => $pageId,
                'instagram_actor_id' => $instagramActorId ?: null,
                'video_data'         => $videoData,
            ]),
        ];

        return $this->createWithIgFallback($adAccount, $payload, $instagramActorId);
    }

    private function createWithIgFallback(string $adAccount, array $payload, ?string $instagramActorId): string
    {
        try {
            $data = $this->meta->request("/act_{$adAccount}/adcreatives", 'POST', $payload);
            return $data['id'];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if ($instagramActorId && str_contains($msg, 'instagram_actor_id') && str_contains($msg, 'must be a valid Instagram account id')) {
                // Retry without instagram_actor_id
                $spec = $payload['object_story_spec'] ?? [];
                unset($spec['instagram_actor_id']);
                $payload['object_story_spec'] = $spec;
                $data = $this->meta->request("/act_{$adAccount}/adcreatives", 'POST', $payload);
                return $data['id'];
            }
            throw $e;
        }
    }

    private function link(string $campaignType): string
    {
        return $this->config->defaultDestinationUrl;
    }

    private function cta(string $campaignType): string
    {
        return $campaignType === 'whatsapp' ? 'WHATSAPP_MESSAGE' : 'LEARN_MORE';
    }
}
