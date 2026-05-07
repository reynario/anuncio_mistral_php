<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Report\InsightsAggregator;
use App\Services\MetaApiService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ReportHandler
{
    public function __construct(
        private readonly MetaApiService $meta,
        private readonly InsightsAggregator $agg,
        private readonly PDO $pdo,
    ) {}

    public function __invoke(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        return $this->buildReport($req, $res);
    }

    private function buildReport(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        try {
            $qs          = $req->getQueryParams();
            $adAccountId = trim(preg_replace('/^act_/', '', $qs['ad_account_id'] ?? '') ?? '');
            if (!$adAccountId) {
                return $this->json($res, ['error' => 'ad_account_id e obrigatorio.'], 400);
            }

            $clientPageId = trim($qs['client_page_id'] ?? '');
            $now          = new \DateTimeImmutable();
            $dateFrom     = $qs['date_from'] ?? $now->modify('-29 days')->format('Y-m-d');
            $dateTo       = $qs['date_to']   ?? $now->format('Y-m-d');
            $prev         = $this->agg->calcPrevPeriod($dateFrom, $dateTo);

            $insightParams = [
                'fields'       => implode(',', [
                    'campaign_name', 'campaign_id',
                    'impressions', 'reach', 'clicks', 'inline_link_clicks', 'spend',
                    'ctr', 'cpm', 'frequency',
                    'actions', 'video_p25_watched_actions', 'video_p50_watched_actions',
                    'video_p75_watched_actions', 'video_p95_watched_actions',
                ]),
                'time_range'   => json_encode(['since' => $dateFrom, 'until' => $dateTo]),
                'level'        => 'campaign',
                'limit'        => 500,
            ];

            $data     = $this->meta->request("/act_{$adAccountId}/insights", 'GET', $insightParams);
            $rows     = $data['data'] ?? [];

            $prevParams = array_merge($insightParams, [
                'time_range' => json_encode(['since' => $prev['since'], 'until' => $prev['until']]),
            ]);
            $prevData = $this->meta->requestSafe("/act_{$adAccountId}/insights", $prevParams) ?? [];
            $prevRows = $prevData['data'] ?? [];

            // Client filter
            if ($clientPageId) {
                $page = $this->pdo->prepare("SELECT * FROM pages WHERE page_id = ?");
                $page->execute([$clientPageId]);
                $pageRow = $page->fetch();
                $clientName = $pageRow ? ($pageRow['cliente'] ?: $pageRow['page_name']) : '';

                $filterFn = fn ($r) =>
                    str_contains(strtolower($r['campaign_name'] ?? ''), strtolower($clientName)) ||
                    str_contains(strtolower($r['campaign_name'] ?? ''), strtolower($clientPageId));

                $rows     = array_values(array_filter($rows, $filterFn));
                $prevRows = array_values(array_filter($prevRows, $filterFn));
            }

            $summary     = $this->agg->buildSummary($rows);
            $prevSummary = $this->agg->buildSummary($prevRows);

            // Timeline: daily breakdown via time_series
            $timelineParams = array_merge($insightParams, ['time_increment' => 1]);
            $tlData    = $this->meta->requestSafe("/act_{$adAccountId}/insights", $timelineParams) ?? [];
            $timeline  = $tlData['data'] ?? [];

            $prevTlParams = array_merge($timelineParams, [
                'time_range' => json_encode(['since' => $prev['since'], 'until' => $prev['until']]),
            ]);
            $prevTlData   = $this->meta->requestSafe("/act_{$adAccountId}/insights", $prevTlParams) ?? [];
            $prevTimeline = $prevTlData['data'] ?? [];

            $reachByDow    = $this->agg->aggregateByDow($timeline, 'reach');
            $messagesByDow = $this->agg->aggregateByDow($timeline, 'conversations');

            // Per-campaign rows
            $campaignRows = array_map(fn ($r) => array_merge($r, [
                'conversations_started' => $this->agg->extractAction($r, 'onsite_conversion.messaging_conversation_started_7d'),
            ]), $rows);

            $res->getBody()->write(json_encode([
                'summary'       => $summary,
                'prev_summary'  => $prevSummary,
                'timeline'      => $timeline,
                'prev_timeline' => $prevTimeline,
                'reach_by_dow'  => $reachByDow,
                'messages_by_dow' => $messagesByDow,
                'campaigns'     => $campaignRows,
            ]));
            return $res->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->json($res, ['error' => $e->getMessage()], 500);
        }
    }

    private function json(ResponseInterface $res, array $data, int $status = 200): ResponseInterface
    {
        $res->getBody()->write(json_encode($data));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
