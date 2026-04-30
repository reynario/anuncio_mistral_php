<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\DropboxService;
use App\Services\GoogleDriveService;
use App\Services\MediaGroupingService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PreviewFolderHandler
{
    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly DropboxService $dropbox,
        private readonly MediaGroupingService $grouper,
    ) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        try {
            $body      = (array) ($req->getParsedBody() ?? []);
            $folderUrl = trim($body['folder_url'] ?? '');

            if (!$folderUrl) {
                $res->getBody()->write(json_encode(['error' => 'folder_url e obrigatoria.']));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            if ($this->grouper->isDropboxUrl($folderUrl)) {
                $files = $this->dropbox->listFolderUrl($folderUrl);
            } else {
                $folderId = $this->grouper->extractFolderId($folderUrl);
                $files    = $this->drive->listFolder($folderId);
            }

            $ads        = $this->grouper->groupAdsFromFiles($files);
            $mediaFiles = $this->grouper->classifyMediaFiles($files);

            $matchedIds = [];
            foreach ($ads as $ad) {
                foreach ($ad['files'] as $f) {
                    $matchedIds[$f['id']] = true;
                }
            }
            $ungrouped = array_values(array_filter($mediaFiles, fn ($f) => !isset($matchedIds[$f['id']])));

            $res->getBody()->write(json_encode([
                'total_files'          => count($files),
                'ads'                  => $ads,
                'media_files'          => $mediaFiles,
                'ungrouped_media_files' => $ungrouped,
            ]));
            return $res->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $res->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
