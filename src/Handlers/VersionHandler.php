<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config\AppConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class VersionHandler
{
    public function __construct(private readonly AppConfig $config) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $res->getBody()->write(json_encode([
            'app'         => 'anuncios-meta-mistral-php',
            'version'     => $this->config->buildVersion,
            'meta_api_url' => $this->config->metaApiUrl,
            'now'         => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
