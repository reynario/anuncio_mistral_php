<?php

declare(strict_types=1);

namespace App\Services;

class MediaGroupingService
{
    private const IMAGE_EXT = ['.jpg', '.jpeg', '.png', '.webp', '.gif'];
    private const VIDEO_EXT = ['.mp4', '.mov', '.avi', '.mkv', '.webm'];

    public function isDropboxUrl(string $url): bool
    {
        return str_contains(strtolower($url), 'dropbox.com');
    }

    public function extractFolderId(string $folderUrl): string
    {
        $parsed = parse_url($folderUrl);
        $path   = $parsed['path'] ?? '';

        if (preg_match('#/folders/([^/?&]+)#', $path, $m)) {
            return $m[1];
        }
        if (preg_match('#/d/([^/?&]+)#', $path, $m)) {
            return $m[1];
        }

        parse_str($parsed['query'] ?? '', $qs);
        if (!empty($qs['id'])) {
            return $qs['id'];
        }

        throw new \InvalidArgumentException("Nao foi possivel extrair o folder ID da URL: {$folderUrl}");
    }

    public function guessDropboxMimeType(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
            'webm' => 'video/webm',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    public function groupAdsFromFiles(array $files): array
    {
        $groups  = [];
        $pattern = '/^(.+?)_(feed|stories|video)(\.[^.]+)?$/i';

        foreach ($files as $file) {
            $name = $file['name'] ?? '';
            if (!preg_match($pattern, $name, $m)) {
                continue;
            }
            $adName = trim($m[1]);
            $kind   = strtolower($m[2]);
            if (!isset($groups[$adName])) {
                $groups[$adName] = ['feed' => null, 'stories' => null, 'video' => null];
            }
            $groups[$adName][$kind] = $file;
        }

        $ads = [];
        foreach ($groups as $name => $assets) {
            if ($assets['video']) {
                $ads[] = ['name' => $name, 'type' => 'video', 'files' => [$assets['video']], 'text' => '', 'title' => '', 'desc' => ''];
                continue;
            }
            if ($assets['feed'] && $assets['stories']) {
                $ads[] = ['name' => $name, 'type' => 'feed+stories', 'files' => [$assets['feed'], $assets['stories']], 'text' => '', 'title' => '', 'desc' => ''];
                continue;
            }
            if ($assets['feed']) {
                $ads[] = ['name' => $name, 'type' => 'feed', 'files' => [$assets['feed']], 'text' => '', 'title' => '', 'desc' => ''];
            }
        }
        return $ads;
    }

    public function classifyMediaFiles(array $files): array
    {
        return array_values(array_filter(array_map(function ($f) {
            $mime = strtolower($f['mimeType'] ?? '');
            $name = strtolower($f['name'] ?? '');
            $isImage = str_starts_with($mime, 'image/') || $this->hasExt($name, self::IMAGE_EXT);
            $isVideo = str_starts_with($mime, 'video/') || $this->hasExt($name, self::VIDEO_EXT);
            if (!$isImage && !$isVideo) {
                return null;
            }
            return array_merge($f, [
                'inferred_type' => ($isVideo || str_starts_with($mime, 'video/')) ? 'video' : 'feed',
            ]);
        }, $files)));
    }

    private function hasExt(string $name, array $exts): bool
    {
        foreach ($exts as $ext) {
            if (str_ends_with($name, $ext)) {
                return true;
            }
        }
        return false;
    }

    public function normalizeAdAccountId(string $id): string
    {
        return preg_replace('/^act_/', '', $id) ?? $id;
    }
}
