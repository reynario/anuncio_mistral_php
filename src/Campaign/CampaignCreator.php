<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Services\DropboxService;
use App\Services\GoogleDriveService;
use App\Services\JobQueueService;
use App\Services\MediaGroupingService;
use App\Services\MetaApiService;
use PDO;

class CampaignCreator
{
    public function __construct(
        private readonly MetaApiService $meta,
        private readonly GoogleDriveService $drive,
        private readonly DropboxService $dropbox,
        private readonly JobQueueService $jobs,
        private readonly MediaUploader $uploader,
        private readonly CreativeFactory $creatives,
        private readonly AdsetBuilder $adsets,
        private readonly MediaGroupingService $grouper,
        private readonly PDO $pdo,
    ) {}

    public function run(string $jobId, array $payload): void
    {
        try {
            $this->jobs->pushLog($jobId, 'info', 'Job iniciado');

            // --- Build Drive file lookup ---
            $driveFileByName = [];
            if (!empty($payload['folder_url'])) {
                try {
                    $folderUrl = $payload['folder_url'];
                    if ($this->grouper->isDropboxUrl($folderUrl)) {
                        $folderFiles = $this->dropbox->listFolderUrl($folderUrl);
                    } else {
                        $folderId    = $this->grouper->extractFolderId($folderUrl);
                        $folderFiles = $this->drive->listFolder($folderId);
                    }
                    foreach ($folderFiles as $f) {
                        $driveFileByName[strtolower(trim($f['name'] ?? ''))] = $f;
                    }
                } catch (\Throwable $e) {
                    $this->jobs->pushLog($jobId, 'warn', 'Nao foi possivel montar lookup de arquivos: ' . $e->getMessage());
                }
            }

            // --- Resolve account, page, geo ---
            $pageId      = trim($payload['page_id'] ?? '');
            $adAccount   = preg_replace('/^act_/', '', trim($payload['ad_account_id'] ?? ''));
            $campaignType = strtolower(trim($payload['campaign_type'] ?? ''));

            $pageRow = null;
            if ($pageId) {
                $stmt = $this->pdo->prepare("SELECT * FROM pages WHERE page_id = ?");
                $stmt->execute([$pageId]);
                $pageRow = $stmt->fetch() ?: null;
            }

            $leadFormId       = $payload['lead_form_id'] ?? $pageRow['lead_form_id'] ?? '';
            $rawIgActorId     = $payload['instagram_actor_id'] ?? $pageRow['instagram_actor_id'] ?? '';
            $instagramActorId = preg_match('/^\d+$/', trim((string) $rawIgActorId)) ? trim((string) $rawIgActorId) : '';
            $whatsappPhoneId  = $payload['whatsapp_phone_number_id'] ?? $pageRow['primary_whatsapp_phone_id'] ?? '';

            // Validate Instagram actor against ad account
            $igActors = $this->meta->fetchAdAccountInstagramActors($adAccount);
            if (is_array($igActors)) {
                $knownIds = array_column($igActors, 'id');
                $knownUsernames = array_column($igActors, 'username');

                if ($instagramActorId && !in_array($instagramActorId, $knownIds, true)) {
                    $this->jobs->pushLog($jobId, 'warn', "instagram_actor_id {$instagramActorId} nao pertence a conta act_{$adAccount}.");
                    $instagramActorId = '';
                }
                if (!$instagramActorId) {
                    $igUsername = strtolower(trim($pageRow['instagram_username'] ?? ''));
                    foreach ($igActors as $actor) {
                        if (strtolower(trim($actor['username'] ?? '')) === $igUsername) {
                            $instagramActorId = $actor['id'];
                            break;
                        }
                    }
                }
            }

            // --- Geo ---
            $geoTarget = null;
            $geoRow    = null;
            $geoKey    = $payload['geo_key'] ?? '';
            if ($geoKey) {
                $stmt = $this->pdo->prepare("SELECT * FROM geo_targets WHERE geo_key = ?");
                $stmt->execute([$geoKey]);
                $geoRow = $stmt->fetch() ?: null;
            }
            if ($geoRow) {
                $regions = array_values(array_filter(
                    array_map(fn ($r) => ['key' => trim($r['key'] ?? ''), 'name' => $r['name'] ?? ''],
                        json_decode($geoRow['regions_json'] ?? '[]', true) ?? []
                    ),
                    fn ($r) => $r['key'] !== ''
                ));
                $defaultRadius = max(1, (int) ($geoRow['radius_km'] ?? 10));
                $cities = array_values(array_filter(
                    array_map(fn ($c) => [
                        'key'           => trim($c['key'] ?? ''),
                        'radius'        => max(1, (int) ($c['radius'] ?? $defaultRadius)),
                        'distance_unit' => 'kilometer',
                    ], json_decode($geoRow['cities_json'] ?? '[]', true) ?? []),
                    fn ($c) => $c['key'] !== ''
                ));
                $countries = array_values(array_filter(
                    array_map(fn ($c) => trim($c), explode(',', $geoRow['countries'] ?? ''))
                ));
                $geoLocations = [];
                if ($cities)   { $geoLocations['cities']   = $cities; }
                if ($regions)  { $geoLocations['regions']  = $regions; }
                if (!$cities && !$regions && $countries) { $geoLocations['countries'] = $countries; }
                $geoTarget = ['geo_locations' => $geoLocations];
            }

            // --- Names ---
            $locationName = $geoRow['name'] ?? '';
            $baseName     = $this->buildName($payload['client_name'], $locationName, $payload['investment_value']);
            $campaignName = $payload['campaign_name'] ?? $baseName;

            // --- Create campaign (retry for is_adset_budget_sharing_enabled) ---
            $this->jobs->pushLog($jobId, 'info', 'Criando nova campanha...');
            $objective  = $campaignType === 'whatsapp' ? 'OUTCOME_ENGAGEMENT' : 'OUTCOME_TRAFFIC';
            $campaignId = $this->createCampaign($adAccount, $campaignName, $objective, $jobId);
            $this->jobs->pushLog($jobId, 'info', "Campanha criada: {$campaignId}");

            // --- Create adset ---
            $this->jobs->pushLog($jobId, 'info', 'Criando conjunto...');
            $adset = $this->adsets->create([
                'campaignId'            => $campaignId,
                'adAccount'             => $adAccount,
                'campaignType'          => $campaignType,
                'pageId'                => $pageId,
                'whatsappPhoneNumberId' => $whatsappPhoneId,
                'geoTarget'             => $geoTarget,
                'adsetName'             => $baseName,
                'investmentType'        => $payload['investment_type'],
                'investmentValue'       => $payload['investment_value'],
                'endDate'               => $payload['end_date'] ?? null,
                'gender'                => $payload['gender'] ?? '',
                'ageMin'                => $payload['age_min'] ?? '',
                'ageMax'                => $payload['age_max'] ?? '',
                'platforms'             => $payload['platforms'] ?? null,
            ]);
            $adsetId = $adset['id'];
            $this->jobs->pushLog($jobId, 'info', "Conjunto criado: {$adsetId}");

            // Verify saved targeting
            try {
                $saved = $this->meta->requestSafe("/{$adsetId}", [
                    'fields' => 'targeting{publisher_platforms,facebook_positions,instagram_positions,device_platforms}',
                ]);
                $t = $saved['targeting'] ?? [];
                $this->jobs->pushLog($jobId, 'info', sprintf(
                    'Targeting salvo: publisher_platforms=%s facebook_positions=%s instagram_positions=%s device_platforms=%s',
                    json_encode($t['publisher_platforms'] ?? []),
                    json_encode($t['facebook_positions'] ?? []),
                    json_encode($t['instagram_positions'] ?? []),
                    json_encode($t['device_platforms'] ?? [])
                ));
            } catch (\Throwable) {}

            // --- Create ads ---
            foreach ($payload['ads'] ?? [] as $ad) {
                $ad['name'] = $baseName;
                try {
                    $this->jobs->pushLog($jobId, 'info', "Criando anuncio: {$ad['name']}");
                    $adId = $this->createAd($ad, $adAccount, $adsetId, $pageId, $campaignType, $instagramActorId, $driveFileByName, $jobId);
                    $this->jobs->pushLog($jobId, 'info', "Anuncio criado com sucesso: {$adId}");
                } catch (\Throwable $e) {
                    $this->jobs->pushLog($jobId, 'error', "Falha no anuncio \"{$ad['name']}\": " . $e->getMessage());
                }
            }

            $this->jobs->endJob($jobId, 'completed');
        } catch (\Throwable $e) {
            $this->jobs->pushLog($jobId, 'error', $e->getMessage());
            $this->jobs->endJob($jobId, 'error', $e->getMessage());
        }
    }

    private function createCampaign(string $adAccount, string $name, string $objective, string $jobId): string
    {
        $base = [
            'name'              => $name,
            'objective'         => $objective,
            'status'            => 'PAUSED',
            'special_ad_categories' => [],
            'is_adset_budget_sharing_enabled' => 'false',
        ];

        $attempts = [
            $base,
            array_merge($base, ['is_adset_budget_sharing_enabled' => false]),
            array_merge($base, ['is_adset_budget_sharing_enabled' => 'False']),
            array_merge($base, ['is_adset_budget_sharing_enabled' => 0]),
        ];

        $lastError = null;
        foreach ($attempts as $i => $payload) {
            $n = $i + 1;
            $this->jobs->pushLog($jobId, 'info', "Tentativa {$n}/" . count($attempts) . " campanha com is_adset_budget_sharing_enabled={$payload['is_adset_budget_sharing_enabled']}");
            try {
                $data = $this->meta->request("/act_{$adAccount}/campaigns", 'POST', $payload);
                return $data['id'];
            } catch (\Throwable $e) {
                $lastError = $e;
                $msg = $e->getMessage();
                if (!str_contains($msg, '4834011') && !str_contains($msg, 'is_adset_budget_sharing_enabled')) {
                    throw $e;
                }
            }
        }

        throw $lastError ?? new \RuntimeException('Falha ao criar campanha.');
    }

    private function createAd(array $ad, string $adAccount, string $adsetId, string $pageId, string $campaignType, string $instagramActorId, array $driveFileByName, string $jobId): string
    {
        $files = $this->resolveFiles($ad['files'] ?? [], $driveFileByName);

        if ($ad['type'] === 'video') {
            $this->jobs->pushLog($jobId, 'info', "Subindo video para \"{$ad['name']}\"");
            $videoFile = $this->findFile($files, '_video') ?? $files[0] ?? null;
            if (!($videoFile['id'] ?? null)) {
                throw new \RuntimeException('Arquivo de video ausente no anuncio.');
            }
            $bytes  = $this->downloadFile($videoFile);
            $result = $this->uploader->uploadVideo($adAccount, $videoFile, $bytes, $jobId, $this->jobs);
            $creativeId = $this->creatives->createVideo(
                $adAccount, $ad, $pageId, $campaignType,
                $result['videoId'], $result['thumbnailUrl'], $instagramActorId
            );
        } elseif ($ad['type'] === 'feed+stories') {
            $this->jobs->pushLog($jobId, 'info', "Subindo feed+stories para \"{$ad['name']}\"");
            $feedFile    = $this->findFile($files, '_feed') ?? $files[0] ?? null;
            $storiesFile = $this->findFile($files, '_stories') ?? $files[1] ?? null;
            if (!($feedFile['id'] ?? null)) {
                throw new \RuntimeException('Arquivo de feed ausente no anuncio.');
            }

            $feedHash = $this->uploader->uploadImage($adAccount, $feedFile, $this->downloadFile($feedFile));

            if ($campaignType === 'whatsapp') {
                $creativeId = $this->creatives->createFeed(
                    $adAccount, $ad, $pageId, $campaignType, $feedHash, $instagramActorId, $jobId
                );
            } else {
                if (!($storiesFile['id'] ?? null)) {
                    throw new \RuntimeException('Arquivo de stories ausente no anuncio.');
                }
                $storiesHash = $this->uploader->uploadImage($adAccount, $storiesFile, $this->downloadFile($storiesFile));
                $creativeId  = $this->creatives->createFeedStories(
                    $adAccount, $ad, $pageId, $campaignType, $feedHash, $storiesHash, $instagramActorId
                );
            }
        } else {
            // feed
            $this->jobs->pushLog($jobId, 'info', "Subindo feed para \"{$ad['name']}\"");
            $feedFile = $this->findFile($files, '_feed') ?? $files[0] ?? null;
            if (!($feedFile['id'] ?? null)) {
                throw new \RuntimeException('Arquivo de imagem ausente no anuncio.');
            }
            $imageHash  = $this->uploader->uploadImage($adAccount, $feedFile, $this->downloadFile($feedFile));
            $creativeId = $this->creatives->createFeed(
                $adAccount, $ad, $pageId, $campaignType, $imageHash, $instagramActorId, $jobId
            );
        }

        $adCreated = $this->meta->request("/act_{$adAccount}/ads", 'POST', [
            'name'     => $ad['name'],
            'adset_id' => $adsetId,
            'creative' => ['creative_id' => $creativeId],
            'status'   => 'PAUSED',
        ]);
        return $adCreated['id'];
    }

    private function resolveFiles(array $files, array $driveFileByName): array
    {
        return array_values(array_filter(array_map(function ($f) use ($driveFileByName) {
            if (is_array($f) && isset($f['id'])) {
                return $f;
            }
            $name = is_string($f) ? $f : ($f['name'] ?? '');
            return $driveFileByName[strtolower(trim($name))] ?? $f;
        }, $files)));
    }

    private function findFile(array $files, string $suffix): ?array
    {
        foreach ($files as $f) {
            if (is_array($f) && str_contains(strtolower($f['name'] ?? ''), $suffix)) {
                return $f;
            }
        }
        return null;
    }

    private function downloadFile(array $file): string
    {
        if (($file['source'] ?? '') === 'dropbox') {
            return $this->dropbox->downloadFile($file);
        }
        return $this->drive->downloadFile($file['id']);
    }

    private function buildName(string $clientName, string $locationName, string $investmentValue): string
    {
        $value = str_replace('.', ',', $investmentValue);
        $loc   = $locationName ? " - {$locationName}" : '';
        $date  = (new \DateTimeImmutable())->format('d/m/Y');
        return "{$clientName}{$loc} - {$value} [{$date}]";
    }
}
