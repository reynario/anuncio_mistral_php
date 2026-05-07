<?php

declare(strict_types=1);

namespace App\Report;

class InsightsAggregator
{
    public function calcPrevPeriod(string $dateFrom, string $dateTo): array
    {
        $from   = new \DateTimeImmutable($dateFrom);
        $to     = new \DateTimeImmutable($dateTo);
        $days   = (int) $from->diff($to)->days + 1;
        $prevTo = $from->modify('-1 day');
        $prevFrom = $prevTo->modify("-{$days} days")->modify('+1 day');
        return [
            'since' => $prevFrom->format('Y-m-d'),
            'until' => $prevTo->format('Y-m-d'),
        ];
    }

    public function extractAction(array $row, string $actionType): float
    {
        foreach ($row['actions'] ?? [] as $a) {
            if (($a['action_type'] ?? '') === $actionType) {
                return (float) ($a['value'] ?? 0);
            }
        }
        return 0.0;
    }

    public function extractVideoMetric(array $row, string $field): float
    {
        foreach ($row['video_p25_watched_actions'] ?? [] as $a) {
            if ($field === 'video_p25') {
                return (float) ($a['value'] ?? 0);
            }
        }
        $map = [
            'video_p25' => 'video_p25_watched_actions',
            'video_p50' => 'video_p50_watched_actions',
            'video_p75' => 'video_p75_watched_actions',
            'video_p95' => 'video_p95_watched_actions',
        ];
        $key = $map[$field] ?? null;
        if (!$key) {
            return 0.0;
        }
        $val = 0.0;
        foreach ($row[$key] ?? [] as $a) {
            $val += (float) ($a['value'] ?? 0);
        }
        return $val;
    }

    public function buildSummary(array $rows): array
    {
        $impressions   = 0.0;
        $reach         = 0.0;
        $clicks        = 0.0;
        $linkClicks    = 0.0;
        $spend         = 0.0;
        $videoP25      = 0.0;
        $videoP50      = 0.0;
        $videoP75      = 0.0;
        $videoP95      = 0.0;
        $conversations = 0.0;
        $igVisits      = 0.0;

        foreach ($rows as $r) {
            $impressions += (float) ($r['impressions'] ?? 0);
            $reach       += (float) ($r['reach'] ?? 0);
            $clicks      += (float) ($r['clicks'] ?? 0);
            $linkClicks  += (float) ($r['inline_link_clicks'] ?? 0);
            $spend       += (float) ($r['spend'] ?? 0);
            $videoP25    += $this->extractVideoMetric($r, 'video_p25');
            $videoP50    += $this->extractVideoMetric($r, 'video_p50');
            $videoP75    += $this->extractVideoMetric($r, 'video_p75');
            $videoP95    += $this->extractVideoMetric($r, 'video_p95');
            $conversations += $this->extractAction($r, 'onsite_conversion.messaging_conversation_started_7d');
            $igVisits    += $this->extractAction($r, 'page_engaged_users');
        }

        $ctr       = $impressions > 0 ? ($linkClicks / $impressions) * 100 : 0.0;
        $cpm       = $impressions > 0 ? ($spend / $impressions) * 1000 : 0.0;
        $frequency = $reach > 0 ? $impressions / $reach : 0.0;
        $cpc       = $conversations > 0 ? $spend / $conversations : null;

        return compact(
            'impressions', 'reach', 'clicks', 'spend',
            'ctr', 'cpm', 'frequency',
            'videoP25', 'videoP50', 'videoP75', 'videoP95',
            'conversations', 'igVisits', 'cpc'
        ) + [
            'inline_link_clicks'          => $linkClicks,
            'video_p25'                   => $videoP25,
            'video_p50'                   => $videoP50,
            'video_p75'                   => $videoP75,
            'video_p95'                   => $videoP95,
            'conversations_started'       => $conversations,
            'cost_per_conversation'       => $cpc,
        ];
    }

    public function aggregateByDate(array $rows): array
    {
        $byDate = [];
        $numericFields = ['impressions', 'reach', 'clicks', 'inline_link_clicks', 'spend'];
        $actionFields  = [
            'actions',
            'video_p25_watched_actions',
            'video_p50_watched_actions',
            'video_p75_watched_actions',
            'video_p95_watched_actions',
        ];

        foreach ($rows as $r) {
            $date = $r['date_start'] ?? '';
            if (!$date) {
                continue;
            }
            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'date_start' => $date,
                    'date_stop'  => $r['date_stop'] ?? $date,
                ];
                foreach ($numericFields as $f) {
                    $byDate[$date][$f] = 0.0;
                }
                foreach ($actionFields as $f) {
                    $byDate[$date][$f] = [];
                }
            }
            foreach ($numericFields as $f) {
                $byDate[$date][$f] += (float) ($r[$f] ?? 0);
            }
            foreach ($actionFields as $f) {
                foreach ($r[$f] ?? [] as $a) {
                    $type = $a['action_type'] ?? '';
                    if (!$type) {
                        continue;
                    }
                    $found = false;
                    foreach ($byDate[$date][$f] as &$existing) {
                        if (($existing['action_type'] ?? '') === $type) {
                            $existing['value'] = (string) ((float) ($existing['value'] ?? 0) + (float) ($a['value'] ?? 0));
                            $found = true;
                            break;
                        }
                    }
                    unset($existing);
                    if (!$found) {
                        $byDate[$date][$f][] = $a;
                    }
                }
            }
        }

        ksort($byDate);
        return array_values($byDate);
    }

    public function aggregateByDow(array $rows, string $metric): array
    {
        $totals = array_fill(0, 7, 0.0);
        foreach ($rows as $r) {
            $date  = $r['date_start'] ?? '';
            $dow   = $date ? (int) (new \DateTimeImmutable($date))->format('w') : -1;
            if ($dow < 0 || $dow > 6) {
                continue;
            }
            if ($metric === 'conversations') {
                $totals[$dow] += $this->extractAction($r, 'onsite_conversion.messaging_conversation_started_7d');
            } else {
                $totals[$dow] += (float) ($r[$metric] ?? 0);
            }
        }
        return $totals;
    }
}
