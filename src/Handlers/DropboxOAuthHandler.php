<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\DropboxService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DropboxOAuthHandler
{
    public function __construct(private readonly DropboxService $dropbox) {}

    public function start(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $url = $this->dropbox->getAuthUrl();
        $res->getBody()->write(json_encode(['auth_url' => $url]));
        return $res->withHeader('Content-Type', 'application/json');
    }

    public function callback(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $params = $req->getQueryParams();
        $code   = $params['code'] ?? '';

        if (!$code) {
            $res->getBody()->write('<p>Erro: parametro <code>code</code> ausente.</p>');
            return $res->withHeader('Content-Type', 'text/html')->withStatus(400);
        }

        try {
            $tokens = $this->dropbox->exchangeCode($code);
            $json   = htmlspecialchars(json_encode($tokens, JSON_PRETTY_PRINT), ENT_QUOTES);
            $res->getBody()->write("<h2>Tokens Dropbox</h2><pre>{$json}</pre><p>Copie o <code>refresh_token</code> para DROPBOX_REFRESH_TOKEN no .env</p>");
            return $res->withHeader('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            $res->getBody()->write('<p>Erro: ' . htmlspecialchars($e->getMessage()) . '</p>');
            return $res->withHeader('Content-Type', 'text/html')->withStatus(500);
        }
    }
}
