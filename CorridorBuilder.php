<?php
require_once __DIR__.'/Logger.php';
require_once __DIR__.'/GrafanaProxyClient.php';
require_once __DIR__.'/ResponseFormatter.php';
require_once __DIR__.'/CacheManagerFactory.php';
require_once __DIR__.'/DataProcessor.php';
require_once __DIR__.'/DFTProcessor.php';
require_once __DIR__.'/AnomalyDetector.php';
require_once __DIR__.'/PerformanceMonitor.php';
require_once __DIR__.'/StatsCacheManager.php';
require_once __DIR__.'/CorridorWidthEnsurer.php';

class CorridorBuilder
{
    private $client;
    private $logger;
    private $config;
    private $responseFormatter;
    private $cacheManager;
    private $dataProcessor;
    private $dftProcessor;
    private $anomalyDetector;
    private $statsCacheManager;

    public function __construct(string $grafanaUrl, Logger $logger, array $config)
    {
        $this->config = $config;
        $this->logger = $logger;

        PerformanceMonitor::init(
            $config['performance']['enabled'] ?? false,
            $config['performance']['threshold_ms'] ?? 5.0
        );

        $this->client           = new GrafanaProxyClient($grafanaUrl, $config['grafana_api_token'] ?? '', $logger);
        $this->responseFormatter= new ResponseFormatter($config);
        $this->cacheManager     = CacheManagerFactory::create($config, $logger);
        $this->dataProcessor    = new DataProcessor($config, $logger);
        $this->dftProcessor     = new DFTProcessor($config, $logger);
        $this->anomalyDetector  = new AnomalyDetector($config, $logger);
        $this->statsCacheManager= new StatsCacheManager(
            $config, $logger,
            $this->cacheManager,
            $this->dataProcessor,
            $this->dftProcessor,
            $this->anomalyDetector,
            $this->responseFormatter
        );
    }

    /**
     * @param string $query
     * @param int    $start
     * @param int    $end
     * @param int    $step
     * @return array  результат для Grafana
     */
    public function build(string $query, int $start, int $end, int $step): array
    {
        // берем show_metrics прямо из текущего конфига (override может туда писать)
        $showMetrics = $this->config['dashboard']['show_metrics'];

        PerformanceMonitor::start('total_processing');
        $results = [];
        $count   = 0;

        // 1) live-данные
        PerformanceMonitor::start('prometheus_fetch');
        $raw = $this->client->queryRange($query, $start, $end, $step);
        PerformanceMonitor::end('prometheus_fetch');

        $grouped = $this->dataProcessor->groupData($raw);

        // 2) исторические данные
        $histStep = $this->config['corrdor_params']['step'];
        $offset   = $this->config['corrdor_params']['historical_offset_days'];
        $period   = $this->config['corrdor_params']['historical_period_days'];
        $histEnd  = time() - $offset * 86400;
        $histStart= $histEnd - $period * 86400;

        PerformanceMonitor::start('long_term_fetch');
        $longRaw = $this->client->queryRange($query, $histStart, $histEnd, $histStep);
        PerformanceMonitor::end('long_term_fetch');

        $longGrouped = $this->dataProcessor->groupData($longRaw);

        // 3) по каждой группе
        foreach ($grouped as $labelsJson => $orig) {
            if ($count++ >= ($this->config['timeout']['max_metrics'] ?? 10)) {
                $this->logger->warn("Превышен лимит метрик", __FILE__, __LINE__);
                break;
            }

            // пытаемся загрузить из кэша
            $cached = $this->cacheManager->loadFromCache($query, $labelsJson);
            $needRecalc = $cached === null
                || $this->cacheManager->shouldRecreateCache($query, $labelsJson, $this->config);

            if ($needRecalc) {
                $cached = $this->statsCacheManager->recalculateStats(
                    $query, $labelsJson, $orig, $longGrouped[$labelsJson] ?? [], $this->config
                );
            }

            // если мало истории — placeholder-режим
            if (count($longGrouped[$labelsJson] ?? []) < ($this->config['corrdor_params']['min_data_points'] ?? 10)) {
                $results[] = $this->statsCacheManager->processInsufficientData(
                    $query, $labelsJson, $orig, $start, $end, $step
                );
                continue;
            }

            // ресторим DFT
            $upper = $this->dftProcessor->restoreFullDFT(
                $cached['dft_upper']['coefficients'],
                $start,$end,$step,
                $cached['meta'], $cached['dft_upper']['trend']
            );
            $lower = $this->dftProcessor->restoreFullDFT(
                $cached['dft_lower']['coefficients'],
                $start,$end,$step,
                $cached['meta'], $cached['dft_lower']['trend']
            );

            // масштаб при scaleCorridor=true
            $factor = $step / $histStep;
            if (!empty($this->config['scaleCorridor']) && abs($factor-1) > 1e-6) {
                foreach ($upper as &$pt) { $pt['value'] *= $factor; }
                foreach ($lower as &$pt) { $pt['value'] *= $factor; }
                $this->logger->info("Масштабирование коридора: {$factor}", __FILE__, __LINE__);
            }

            // корректируем ширину
            list($cU,$cL) = CorridorWidthEnsurer::ensureWidth(
                $upper, $lower,
                $cached['dft_upper']['coefficients'][0]['amplitude'] ?? 0,
                $cached['dft_lower']['coefficients'][0]['amplitude'] ?? 0,
                $this->config, $this->logger
            );

            // аномалии
            $currStats = $this->anomalyDetector->calculateAnomalyStats(
                $orig, $cU, $cL,
                $this->config['corrdor_params']['default_percentiles'],
                true
            );

            // concern-метрики
            $wsize = $this->config['corrdor_params']['window_size'];
            $aboveC = $this->anomalyDetector->calculateIntegralMetric(
                $currStats['above'], $cached['meta']['anomaly_stats']['above'] ?? []
            );
            $belowC = $this->anomalyDetector->calculateIntegralMetric(
                $currStats['below'], $cached['meta']['anomaly_stats']['below'] ?? []
            );
            $aboveS = $this->anomalyDetector->calculateIntegralMetricSum(
                $currStats['above'], $cached['meta']['anomaly_stats']['above'] ?? [], $wsize
            );
            $belowS = $this->anomalyDetector->calculateIntegralMetricSum(
                $currStats['below'], $cached['meta']['anomaly_stats']['below'] ?? [], $wsize
            );

            // собираем
            $item = [
                'labels'                   => json_decode($labelsJson, true),
                'original'                 => $orig,
                'dft_upper'                => $cU,
                'dft_lower'                => $cL,
                'historical_anomaly_stats' => $cached['meta']['anomaly_stats'] ?? [],
                'current_anomaly_stats'    => $currStats,
                'anomaly_concern_above'    => $aboveC,
                'anomaly_concern_below'    => $belowC,
                'anomaly_concern_above_sum'=> $aboveS,
                'anomaly_concern_below_sum'=> $belowS,
                'dft_rebuild_count'        => $cached['meta']['dft_rebuild_count'] ?? 0,
            ];

            $results[] = $item;
        }

        PerformanceMonitor::end('total_processing');
        return $this->responseFormatter->formatForGrafana($results, $query, $showMetrics);
    }
}
