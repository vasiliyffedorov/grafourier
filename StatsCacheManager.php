<?php
require_once __DIR__ . '/DataProcessor.php';
require_once __DIR__ . '/DFTProcessor.php';
require_once __DIR__ . '/AnomalyDetector.php';
require_once __DIR__ . '/ResponseFormatter.php';

class StatsCacheManager
{
    private $config;
    private $logger;
    private $cacheManager;
    private $dataProcessor;
    private $dftProcessor;
    private $anomalyDetector;
    private $responseFormatter;

    public function __construct(
        array $config,
        Logger $logger,
        $cacheManager,
        DataProcessor $dp,
        DFTProcessor $dft,
        AnomalyDetector $an,
        ResponseFormatter $rf
    ) {
        $this->config            = $config;
        $this->logger            = $logger;
        $this->cacheManager      = $cacheManager;
        $this->dataProcessor     = $dp;
        $this->dftProcessor      = $dft;
        $this->anomalyDetector   = $an;
        $this->responseFormatter = $rf;
    }

    /**
     * Пересчет DFT + статистики аномалий с сохранением в кэш
     */
    public function recalculateStats(
        string $query,
        string $labelsJson,
        array $liveData,
        array $historyData,
        array $currentConfig
    ): array {
        // Если метрика помечена как unused — ничего не делаем
        $cached = $this->cacheManager->loadFromCache($query, $labelsJson);
        if (isset($cached['meta']['labels']['unused_metric'])) {
            $this->logger->info("Пропуск пересчета unused_metric", __FILE__, __LINE__);
            return $cached;
        }

        $range = $this->dataProcessor->getActualDataRange($historyData);
        $longStart = $range['start'];
        $longEnd   = $range['end'];
        $longStep  = $this->config['corrdor_params']['step'];

        // Если данных мало — создаем placeholder
        $minPts = $this->config['corrdor_params']['min_data_points'];
        if (count($historyData) < $minPts) {
            $this->logger->warn("Недостаточно долгосрочных данных, placeholder", __FILE__, __LINE__);
            $this->buildPlaceholder($query, $labelsJson, $longStart, $longEnd, $longStep, $currentConfig);
            return $this->cacheManager->loadFromCache($query, $labelsJson) ?: [];
        }

        // 1) генерируем DFT
        $bounds    = $this->dataProcessor->calculateBounds($historyData, $longStart, $longEnd, $longStep);
        $dftResult = $this->dftProcessor->generateDFT($bounds, $longStart, $longEnd, $longStep);

        // фильтруем «нулевые» гармоники
        $dftResult['upper']['coefficients'] = array_filter(
            $dftResult['upper']['coefficients'],
            fn($c) => $c['amplitude'] >= 1e-12
        );
        $dftResult['lower']['coefficients'] = array_filter(
            $dftResult['lower']['coefficients'],
            fn($c) => $c['amplitude'] >= 1e-12
        );

        // 2) восстанавливаем траектории
        $meta = [
            'dataStart'         => $longStart,
            'step'              => $longStep,
            'totalDuration'     => $longEnd - $longStart,
            'config_hash'       => $this->cacheManager->createConfigHash($currentConfig),
            'dft_rebuild_count' => ($cached['meta']['dft_rebuild_count'] ?? 0) + 1,
            'labels'            => json_decode($labelsJson, TRUE),
            'created_at'        => time(),
        ];

        $upperSeries = $this->dftProcessor->restoreFullDFT(
            $dftResult['upper']['coefficients'],
            $longStart, $longEnd, $longStep,
            $meta, $dftResult['upper']['trend']
        );
        $lowerSeries = $this->dftProcessor->restoreFullDFT(
            $dftResult['lower']['coefficients'],
            $longStart, $longEnd, $longStep,
            $meta, $dftResult['lower']['trend']
        );

        // 3) статистики аномалий
        $stats = $this->anomalyDetector->calculateAnomalyStats(
            $historyData, $upperSeries, $lowerSeries,
            $this->config['corrdor_params']['default_percentiles']
        );
        $meta['anomaly_stats'] = $stats;

        // 4) сохраняем
        $payload = [
            'meta'      => $meta,
            'dft_upper' => [
                'coefficients' => $dftResult['upper']['coefficients'],
                'trend'        => $dftResult['upper']['trend']
            ],
            'dft_lower' => [
                'coefficients' => $dftResult['lower']['coefficients'],
                'trend'        => $dftResult['lower']['trend']
            ],
        ];

        $this->cacheManager->saveToCache($query, $labelsJson, $payload, $currentConfig);
        return $payload;
    }

    /**
     * Placeholder: сохраняем пустой кэш
     */
    private function buildPlaceholder(
        string $query,
        string $labelsJson,
        int $start,
        int $end,
        int $step,
        array $currentConfig
    ): void {
        $cfgHash = $this->cacheManager->createConfigHash($currentConfig);
        $labels = json_decode($labelsJson, TRUE);
        $labels['unused_metric'] = 'true';

        $meta = [
            'query'            => $query,
            'labels'           => $labels,
            'created_at'       => time(),
            'is_placeholder'   => true,
            'dataStart'        => $start,
            'step'             => $step,
            'totalDuration'    => $end - $start,
            'config_hash'      => $cfgHash,
            'dft_rebuild_count'=> 0,
            'anomaly_stats'    => [
                'above' => ['time_outside_percent'=>0,'anomaly_count'=>0,'durations'=>[],'sizes'=>[],'direction'=>'above'],
                'below' => ['time_outside_percent'=>0,'anomaly_count'=>0,'durations'=>[],'sizes'=>[],'direction'=>'below'],
                'combined'=>['time_outside_percent'=>0,'anomaly_count'=>0],
            ],
        ];

        $empty = [
            'meta'      => $meta,
            'dft_upper' => ['coefficients'=>[], 'trend'=>['slope'=>0,'intercept'=>0]],
            'dft_lower' => ['coefficients'=>[], 'trend'=>['slope'=>0,'intercept'=>0]],
        ];

        $this->cacheManager->saveToCache($query, $labelsJson, $empty, $currentConfig);
    }

    /**
     * Обработка метрик с недостатком данных
     */
    public function processInsufficientData(
        string $query,
        string $labelsJson,
        array $liveData,
        int $start,
        int $end,
        int $step
    ): array {
        // берем placeholder
        $cached = $this->cacheManager->loadFromCache($query, $labelsJson) ?? ['meta'=>[]];

        // Можем сразу вернуть оригинал без DFT
        $bounds = $this->dataProcessor->calculateBounds($liveData, $start, $end, $step);
        $up   = $this->responseFormatter->resampleDFT($bounds['upper'], $start, $end, $step);
        $down = $this->responseFormatter->resampleDFT($bounds['lower'], $start, $end, $step);

        $labels = json_decode($labelsJson, TRUE);
        $labels['unused_metric'] = 'true';

        return [
            'labels'                  => $labels,
            'original'                => $liveData,
            'dft_upper'               => [],
            'dft_lower'               => [],
            'historical_anomaly_stats'=> $cached['meta']['anomaly_stats'] ?? [],
            'current_anomaly_stats'   => [
                'above'=>['time_outside_percent'=>0,'anomaly_count'=>0,'durations'=>[],'sizes'=>[],'direction'=>'above'],
                'below'=>['time_outside_percent'=>0,'anomaly_count'=>0,'durations'=>[],'sizes'=>[],'direction'=>'below'],
                'combined'=>['time_outside_percent'=>0,'anomaly_count'=>0],
            ],
            'anomaly_concern_above'     => 0,
            'anomaly_concern_below'     => 0,
            'anomaly_concern_above_sum' => 0,
            'anomaly_concern_below_sum' => 0,
            'dft_rebuild_count'         => $cached['meta']['dft_rebuild_count'] ?? 0,
        ];
    }
}
