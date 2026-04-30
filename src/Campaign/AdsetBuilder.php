<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Services\MetaApiService;

class AdsetBuilder
{
    public function __construct(private readonly MetaApiService $meta) {}

    public function create(array $params): array
    {
        [
            'campaignId'            => $campaignId,
            'adAccount'             => $adAccount,
            'campaignType'          => $campaignType,
            'pageId'                => $pageId,
            'whatsappPhoneNumberId' => $whatsappPhoneNumberId,
            'geoTarget'             => $geoTarget,
            'adsetName'             => $adsetName,
            'investmentType'        => $investmentType,
            'investmentValue'       => $investmentValue,
            'endDate'               => $endDate,
            'gender'                => $gender,
            'ageMin'                => $ageMin,
            'ageMax'                => $ageMax,
            'platforms'             => $platforms,
        ] = $params;

        $allowedPlatforms = ['facebook', 'instagram'];
        $selectedPlatforms = array_values(array_filter(
            is_array($platforms) && count($platforms) ? $platforms : $allowedPlatforms,
            fn ($p) => in_array($p, $allowedPlatforms, true)
        ));
        if (!$selectedPlatforms) {
            $selectedPlatforms = $allowedPlatforms;
        }

        $baseTargeting = $geoTarget ?? ['geo_locations' => ['countries' => ['BR']]];
        $targeting = array_merge($baseTargeting, [
            'publisher_platforms' => $selectedPlatforms,
            'device_platforms'    => $campaignType === 'whatsapp' ? ['mobile'] : ['mobile', 'desktop'],
        ]);

        // WhatsApp requires explicit positions; pixel uses Advantage+ (no positions set)
        if ($campaignType === 'whatsapp') {
            if (in_array('facebook', $selectedPlatforms, true)) {
                $targeting['facebook_positions'] = ['feed', 'story', 'facebook_reels'];
            }
            if (in_array('instagram', $selectedPlatforms, true)) {
                $targeting['instagram_positions'] = ['stream', 'story', 'reels'];
            }
        }

        if ($gender === '1' || $gender === '2') {
            $targeting['genders'] = [(int) $gender];
        }
        if ($ageMin) {
            $targeting['age_min'] = (int) $ageMin;
        }
        if ($ageMax) {
            $targeting['age_max'] = (int) $ageMax;
        }

        $budgetMinor = $this->toMinorUnits($investmentValue);
        $budgetField = $investmentType === 'daily'
            ? ['daily_budget' => $budgetMinor]
            : ['lifetime_budget' => $budgetMinor];

        $endTime = $endDate ? (new \DateTimeImmutable("{$endDate}T23:59:59"))->format(\DateTimeInterface::ATOM) : null;

        $whatsappFields = $campaignType === 'whatsapp'
            ? array_filter([
                'optimization_goal'                          => 'CONVERSATIONS',
                'destination_type'                           => 'WHATSAPP',
                'promoted_object[page_id]'                   => $pageId ?: null,
                'promoted_object[whatsapp_phone_number_id]'  => $whatsappPhoneNumberId ?: null,
            ])
            : array_filter([
                'optimization_goal'        => 'LINK_CLICKS',
                'promoted_object[page_id]' => $pageId ?: null,
            ]);

        $adsetParams = array_merge([
            'name'              => $adsetName,
            'campaign_id'       => $campaignId,
            'status'            => 'PAUSED',
            'billing_event'     => 'IMPRESSIONS',
            'bid_strategy'      => 'LOWEST_COST_WITHOUT_CAP',
            'targeting'         => $targeting,
            'end_time'          => $endTime,
        ], $budgetField, $whatsappFields);

        return $this->meta->request("/act_{$adAccount}/adsets", 'POST', $adsetParams);
    }

    private function toMinorUnits(string $value): int
    {
        $normalized = str_replace(',', '.', trim($value));
        $number     = (float) $normalized;
        if (!is_finite($number) || $number <= 0) {
            throw new \InvalidArgumentException('Valor de investimento invalido.');
        }
        return (int) round($number * 100);
    }
}
