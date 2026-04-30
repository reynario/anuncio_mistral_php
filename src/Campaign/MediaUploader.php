<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Services\JobQueueService;
use App\Services\MetaApiService;

class MediaUploader
{
    public function __construct(private readonly MetaApiService $meta) {}

    public function uploadImage(string $adAccount, array $file, string $bytes): string
    {
        $data = $this->meta->request("/act_{$adAccount}/adimages", 'POST', [], [
            ['name' => 'file', 'contents' => $bytes, 'filename' => $file['name'] ?? 'image.jpg'],
        ]);
        $image = array_values($data['images'] ?? [])[0] ?? [];
        if (!isset($image['hash'])) {
            throw new \RuntimeException('Upload de imagem nao retornou hash.');
        }
        return $image['hash'];
    }

    public function uploadVideo(string $adAccount, array $file, string $bytes, string $jobId, JobQueueService $jobs): array
    {
        $fileSize = strlen($bytes);

        $start = $this->meta->request("/act_{$adAccount}/advideos", 'POST', [
            'upload_phase' => 'start',
            'file_size'    => $fileSize,
        ]);

        $uploadSessionId = $start['upload_session_id'] ?? '';
        $videoId         = $start['video_id'] ?? '';
        $startOffset     = (int) ($start['start_offset'] ?? 0);
        $endOffset       = (int) ($start['end_offset'] ?? 0);

        if (!$uploadSessionId || !$videoId) {
            throw new \RuntimeException('Falha ao iniciar upload resumivel de video.');
        }

        // Transfer chunks
        while ($startOffset < $fileSize) {
            $chunk   = substr($bytes, $startOffset, $endOffset - $startOffset);
            $result  = $this->meta->request("/act_{$adAccount}/advideos", 'POST', [], [
                ['name' => 'upload_phase',     'contents' => 'transfer'],
                ['name' => 'upload_session_id', 'contents' => $uploadSessionId],
                ['name' => 'start_offset',     'contents' => (string) $startOffset],
                ['name' => 'video_file_chunk',  'contents' => $chunk, 'filename' => $file['name'] ?? 'video.mp4'],
            ]);
            $newStart = (int) ($result['start_offset'] ?? $endOffset);
            $newEnd   = (int) ($result['end_offset']   ?? $endOffset);
            if ($newStart <= $startOffset) {
                break;
            }
            $startOffset = $newStart;
            $endOffset   = $newEnd;
        }

        // Finish
        $this->meta->request("/act_{$adAccount}/advideos", 'POST', [
            'upload_phase'    => 'finish',
            'upload_session_id' => $uploadSessionId,
        ]);

        // Poll for processing
        $thumbnailUrl = null;
        for ($i = 0; $i < 20; $i++) {
            sleep(3);
            $status = $this->meta->requestSafe("/{$videoId}", ['fields' => 'id,status,thumbnails']);
            $videoStatus = $status['status']['video_status'] ?? '';
            $jobs->pushLog($jobId, 'info', "Video {$videoId} status: {$videoStatus}");
            if ($videoStatus === 'ready') {
                $thumbnails   = $status['thumbnails']['data'] ?? [];
                $thumbnailUrl = $thumbnails[0]['uri'] ?? null;
                break;
            }
            if ($videoStatus === 'error') {
                throw new \RuntimeException("Processamento de video falhou: {$videoId}");
            }
        }

        return ['videoId' => $videoId, 'thumbnailUrl' => $thumbnailUrl];
    }
}
