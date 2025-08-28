<?php
declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

class GrafanaProxyClient
{
    private string $grafanaUrl;
    private string $apiToken;
    private Logger $logger;
    private array $metricsCache = [];
    private array $headers;
    private array $blacklistDatasourceIds; // New property for blacklisted datasource IDs

    /** тип последнего datasource, на который сделали queryRange */
    private string $lastDataSourceType = 'unknown';

    public function __construct(string $grafanaUrl, string $apiToken, Logger $logger, array $blacklistDatasourceIds = [])
    {
        $this->grafanaUrl = rtrim($grafanaUrl, '/');
        $this->apiToken   = $apiToken;
        $this->logger     = $logger;
        $this->blacklistDatasourceIds = $blacklistDatasourceIds; // Initialize blacklist
        $this->headers    = [
            "Authorization: Bearer {$this->apiToken}",
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $this->initMetricsCache();
    }

    /**
     * Возвращает перечень доступных “metrics” (dashboard__panel ключи).
     * (вызывается из index.php для /api/v1/labels и /api/v1/label/__name__/values)
     */
    public function getMetricNames(): array
    {
        return array_keys($this->metricsCache);
    }

    /**
     * Отдаёт тип последнего datasource, на который мы делали queryRange.
     */
    public function getLastDataSourceType(): string
    {
        return $this->lastDataSourceType;
    }

    /**
     * Запрашивает у Grafana /api/ds/query и возвращает
     * массив точек ['time'=>'Y-m-d H:i:s','value'=>float,'labels'=>[]].
     */
    public function queryRange(string $metricName, int $start, int $end, int $step): array
    {
        if (!isset($this->metricsCache[$metricName])) {
            $this->logger->error("Метрика не найдена в кэше Grafana: $metricName", __FILE__, __LINE__);
            return [];
        }

        $info = $this->metricsCache[$metricName];

        // 1) Получаем JSON дашборда
        $dashJson = $this->httpRequest('GET', "{$this->grafanaUrl}/api/dashboards/uid/{$info['dashboard_uid']}");
        if (!$dashJson) {
            $this->logger->error("Не удалось получить JSON дашборда {$info['dashboard_uid']}", __FILE__, __LINE__);
            return [];
        }

        $dashData = json_decode($dashJson, true);
        $panels   = $dashData['dashboard']['panels'] ?? [];
        $target   = null;
        foreach ($panels as $p) {
            if ((string)$p['id'] === $info['panel_id']) {
                $target = $p;
                break;
            }
        }

        if (!$target || empty($target['targets'])) {
            $this->logger->warn("Панель {$info['panel_id']} не содержит targets", __FILE__, __LINE__);
            return [];
        }

        // 2) Определяем тип datasource из первого target
        if (isset($target['targets'][0]['datasource'])) {
            $ds = $target['targets'][0]['datasource'];
            $this->lastDataSourceType = strtolower($ds['type'] ?? $ds['name'] ?? 'unknown');
        } else {
            $this->lastDataSourceType = 'unknown';
        }

        // 3) Формируем запрос к /api/ds/query
        $fromMs = $start * 1000;
        $toMs   = $end   * 1000;
        $stepMs = (int)($step * 1000);

        $queries = [];
        foreach ($target['targets'] as $t) {
            $q = [
                'refId'         => $t['refId']         ?? 'A',
                'datasource'    => $t['datasource']    ?? [],
                'format'        => $t['format']        ?? 'time_series',
                'intervalMs'    => $stepMs,
                'maxDataPoints' => ceil(($toMs - $fromMs) / $stepMs),
            ];
            foreach ($t as $k => $v) {
                if (!isset($q[$k])) {
                    $q[$k] = $v;
                }
            }
            $queries[] = $q;
        }

        $body = json_encode([
            'from'    => (string)$fromMs,
            'to'      => (string)$toMs,
            'queries' => $queries,
        ]);

        $resp = $this->httpRequest('POST', "{$this->grafanaUrl}/api/ds/query", $body);
        if (!$resp) {
            $this->logger->error("DS query для $metricName завершился ошибкой", __FILE__, __LINE__);
            return [];
        }

        return $this->parseFrames(json_decode($resp, true), $info);
    }

    /**
     * Инициализируем $metricsCache из всех дашбордов Grafana.
     */
    private function initMetricsCache(): void
    {
        $resp = $this->httpRequest('GET', "{$this->grafanaUrl}/api/search?type=dash-db");
        if (!$resp) {
            $this->logger->error("Не удалось получить список дашбордов", __FILE__, __LINE__);
            return;
        }

        $dashboards = json_decode($resp, true);
        foreach ($dashboards as $dash) {
            $uid   = $dash['uid'];
            $title = $dash['title'] ?: $uid;
            $dashJson = $this->httpRequest('GET', "{$this->grafanaUrl}/api/dashboards/uid/$uid");
            if (!$dashJson) {
                $this->logger->warn("Не удалось загрузить дашборд $uid", __FILE__, __LINE__);
                continue;
            }
            $dashData = json_decode($dashJson, true);
            $panels = $dashData['dashboard']['panels'] ?? [];
            foreach ($panels as $p) {
                if (empty($p['id'])) {
                    continue;
                }
                // Check if the panel's datasource is in the blacklist
                $datasourceId = $p['targets'][0]['datasource']['uid'] ?? null;
                if ($datasourceId && in_array($datasourceId, $this->blacklistDatasourceIds)) {
                    $this->logger->info("Пропущена панель {$p['id']} в дашборде $uid: datasource $datasourceId в черном списке", __FILE__, __LINE__);
                    continue;
                }
                $panelId    = (string)$p['id'];
                $panelTitle = $p['title'] ?: "Panel_$panelId";
                $key = "{$title}__{$panelTitle}";
                $this->metricsCache[$key] = [
                    'dashboard_uid' => $uid,
                    'panel_id'      => $panelId,
                    'dash_title'    => $title,
                    'panel_title'   => $panelTitle,
                ];
            }
        }

        $this->logger->info(
            "Кэш метрик Grafana инициализирован: " . implode(', ', array_keys($this->metricsCache)),
            __FILE__,
            __LINE__
        );
    }

    /**
     * Выполняет HTTP-запрос к Grafana и возвращает тело или null.
     */
    private function httpRequest(string $method, string $url, ?string $body = null): ?string
    {
        $this->logger->info("Grafana HTTP Request → $method $url\nBody: " . ($body ?? 'none'), __FILE__, __LINE__);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $code >= 400) {
            $this->logger->error("Grafana HTTP Error → $method $url\nCode: $code, Error: " . ($err ?: 'HTTP status >= 400'), __FILE__, __LINE__);
            return null;
        }

        $this->logger->info("Grafana HTTP Response ← Code: $code\nBody (truncated): " . substr($resp ?? '', 0, 1000), __FILE__, __LINE__);
        return $resp;
    }

    /**
     * Конвертирует Grafana frames → Prometheus-like array.
     */
    private function parseFrames(array $data, array $info): array
    {
        $out     = [];
        $results = $data['results'] ?? [];
        foreach ($results as $frameSet) {
            foreach ($frameSet['frames'] as $frame) {
                $times  = $frame['data']['values'][0];
                $fields = $frame['schema']['fields'];
                for ($i = 1, $n = count($fields); $i < $n; $i++) {
                    $vals   = $frame['data']['values'][$i];
                    $labels = $fields[$i]['labels'] ?? [];
                    $labels['__name__'] = $info['dash_title'] . '__' . $info['panel_title'];
                    $labels['panel_url'] = sprintf(
                        '%s/d/%s/%s?viewPanel=%s',
                        $this->grafanaUrl,
                        $info['dashboard_uid'],
                        rawurlencode($info['dash_title']),
                        $info['panel_id']
                    );
                    foreach ($times as $idx => $ts) {
                        if ($vals[$idx] === null) {
                            continue;
                        }
                        $out[] = [
                            'time'   => date('Y-m-d H:i:s', intval($ts / 1000)),
                            'value'  => (float)$vals[$idx],
                            'labels' => $labels,
                        ];
                    }
                }
            }
        }
        return $out;
    }
}
?>