<?php
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/AnomalyDetector.php';

class ResponseFormatter {
    private $showMetrics;
    private $config;
    private $anomalyDetector;

    public function __construct(array $config) {
        $this->config = $config;
        $this->updateConfig($config);
        $this->anomalyDetector = new AnomalyDetector($config, new Logger($config['log_file'], $this->getLogLevel($config['log_level'] ?? 'INFO')));
    }

    private function getLogLevel(string $logLevel): int {
        $logLevelMap = [
            'INFO' => Logger::LEVEL_INFO,
            'WARN' => Logger::LEVEL_WARN,
            'ERROR' => Logger::LEVEL_ERROR
        ];
        return $logLevelMap[strtoupper($logLevel)] ?? Logger::LEVEL_INFO;
    }

    public function updateConfig(array $config): void {
        $this->config = $config;
        $this->showMetrics = $config['dashboard']['show_metrics'] ?? [
            'original',
            'nodata',
            'dft_upper',
            'dft_lower',
            'dft_range',
            'anomaly_stats',
            'anomaly_concern',
            'anomaly_concern_sum',
            'historical_metrics'
        ];
    }

    public function formatForGrafana(array $results, string $query, ?array $filter = null): array {
        $formatted = [
            'status' => 'success',
            'data' => [
                'resultType' => 'matrix',
                'result' => []
            ]
        ];

        $metricsToShow = $filter ?? $this->showMetrics;
        if (is_string($metricsToShow)) {
            $this->anomalyDetector->logger->warn(
                "dashboard.show_metrics should be a comma-separated list (e.g., 'dft_lower,anomaly_concern'). Received: '$metricsToShow'. Treating as single metric.",
                __FILE__,
                __LINE__
            );
            $metricsToShow = [$metricsToShow];
        }

        if (!is_array($metricsToShow)) {
            $this->anomalyDetector->logger->error(
                "Invalid dashboard.show_metrics configuration: expected array, got " . gettype($metricsToShow),
                __FILE__,
                __LINE__
            );
            return [
                'status' => 'error',
                'errorType' => 'invalid_configuration',
                'error' => "Invalid dashboard.show_metrics: expected comma-separated list (e.g., 'dft_lower,anomaly_concern'), got '$metricsToShow'"
            ];
        }

        foreach ($results as $result) {
            $labels = $result['labels'] ?? [];
            $percentileConfig = $this->config['corrdor_params']['default_percentiles'] ?? ['duration' => 75, 'size' => 75];

            if (in_array('original', $metricsToShow) && isset($result['original'])) {
                $this->addMetric($formatted, $labels, $query, 'original', $result['original'] ?? []);
            }

            if (in_array('nodata', $metricsToShow) && isset($result['nodata'])) {
                $this->addMetric($formatted, $labels, $query, 'nodata', $result['nodata'] ?? []);
            }

            if (in_array('dft_upper', $metricsToShow)) {
                $this->addMetric($formatted, $labels, $query, 'dft_upper', $result['dft_upper'] ?? []);
            }

            if (in_array('dft_lower', $metricsToShow)) {
                $this->addMetric($formatted, $labels, $query, 'dft_lower', $result['dft_lower'] ?? []);
            }

            if (in_array('dft_range', $metricsToShow) && isset($result['dft_upper']) && isset($result['dft_lower'])) {
                $range = [];
                foreach ($result['dft_upper'] as $i => $upperPoint) {
                    $lowerPoint = $result['dft_lower'][$i] ?? ['value' => 0];
                    $range[] = [
                        'time' => $upperPoint['time'],
                        'value' => $upperPoint['value'] - $lowerPoint['value']
                    ];
                }
                $this->addMetric($formatted, $labels, $query, 'dft_range', $range);
            }

            $currentStats = $result['current_anomaly_stats'] ?? [];
            $historicalStats = $result['historical_anomaly_stats'] ?? [];

            if (in_array('anomaly_stats', $metricsToShow)) {
                $this->addDirectionalMetrics($formatted, $labels, $query, 'above', $currentStats, $historicalStats, $percentileConfig);
                $this->addDirectionalMetrics($formatted, $labels, $query, 'below', $currentStats, $historicalStats, $percentileConfig);
                $this->addCombinedMetrics($formatted, $labels, $query, $currentStats, $historicalStats);
            }

            if (in_array('anomaly_concern', $metricsToShow)) {
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_above', 'original_query' => $query])),
                    'values' => [[time(), (string)(($result['anomaly_concern_above'] ?? 0) * 100)]]
                ];
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_below', 'original_query' => $query])),
                    'values' => [[time(), (string)(($result['anomaly_concern_below'] ?? 0) * 100)]]
                ];
            }

            if (in_array('anomaly_concern_sum', $metricsToShow)) {
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_above_sum', 'original_query' => $query])),
                    'values' => [[time(), (string)(($result['anomaly_concern_above_sum'] ?? 0) * 100)]]
                ];
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_below_sum', 'original_query' => $query])),
                    'values' => [[time(), (string)(($result['anomaly_concern_below_sum'] ?? 0) * 100)]]
                ];
            }

            if (in_array('historical_metrics', $metricsToShow) && !empty($historicalStats)) {
                $this->addHistoricalMetrics($formatted, $labels, $query, $historicalStats, $percentileConfig);
            }

            if (in_array('dft_rebuild_count', $metricsToShow) && isset($result['dft_rebuild_count'])) {
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'dft_rebuild_count', 'original_query' => $query])),
                    'values' => [[time(), (string)$result['dft_rebuild_count']]]
                ];
            }
        }

        return $formatted;
    }

    private function formatMetricLabels(array $labels): array {
        $formatted = [];
        // Ensure __name__ is the first key
        if (isset($labels['__name__'])) {
            $formatted['__name__'] = $labels['__name__'];
            unset($labels['__name__']);
        }
        // Add remaining labels in sorted order
        ksort($labels);
        return array_merge($formatted, $labels);
    }

    private function addMetric(array &$formatted, array $labels, string $query, string $name, array $data): void {
        $formatted['data']['result'][] = [
            'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => $name, 'original_query' => $query])),
            'values' => array_map(
                fn($p) => [(int)($p['time'] ?? 0), (string)round($p['value'] ?? 0, 6)],
                $data
            )
        ];
    }

    private function addDirectionalMetrics(array &$formatted, array $labels, string $query, string $direction, array $currentStats, array $historicalStats, array $percentileConfig): void {
        $prefix = ($direction === 'above') ? 'upper_' : 'lower_';
        $current = $currentStats[$direction] ?? [];
        $historical = $historicalStats[$direction] ?? [];

        $historicalDurationPercentile = $this->anomalyDetector->calculatePercentile(
            $historical['durations'] ?? [],
            $percentileConfig['duration'] ?? 75
        );
        $historicalSizePercentile = $this->anomalyDetector->calculatePercentile(
            $historical['sizes'] ?? [],
            $percentileConfig['size'] ?? 75
        );

        $metrics = [
            'time_outside_percent' => $current['time_outside_percent'] ?? 0,
            'anomaly_count' => $current['anomaly_count'] ?? 0,
            'anomaly_duration' => !empty($current['durations']) ? max($current['durations']) : 0,
            'anomaly_size' => !empty($current['sizes']) ? max($current['sizes']) : 0
        ];

        foreach ($metrics as $metricName => $metricValue) {
            $formatted['data']['result'][] = [
                'metric' => $this->formatMetricLabels(array_merge($labels, [
                    '__name__' => $prefix . $metricName,
                    'original_query' => $query,
                    'direction' => $direction
                ])),
                'values' => [[time(), (string)$metricValue]]
            ];
        }

        $historicalMetrics = [
            'historical_anomaly_duration' => $historicalDurationPercentile,
            'historical_anomaly_size' => $historicalSizePercentile
        ];

        foreach ($historicalMetrics as $metricName => $metricValue) {
            $formatted['data']['result'][] = [
                'metric' => $this->formatMetricLabels(array_merge($labels, [
                    '__name__' => $prefix . $metricName,
                    'original_query' => $query,
                    'direction' => $direction
                ])),
                'values' => [[time(), (string)$metricValue]]
            ];
        }
    }

    private function addCombinedMetrics(array &$formatted, array $labels, string $query, array $currentStats, array $historicalStats): void {
        $combined = $currentStats['combined'] ?? [];
        $combinedMetrics = [
            'combined_time_outside_percent' => $combined['time_outside_percent'] ?? 0,
            'combined_anomaly_count' => $combined['anomaly_count'] ?? 0
        ];

        foreach ($combinedMetrics as $metricName => $metricValue) {
            $formatted['data']['result'][] = [
                'metric' => $this->formatMetricLabels(array_merge($labels, [
                    '__name__' => $metricName,
                    'original_query' => $query
                ])),
                'values' => [[time(), (string)$metricValue]]
            ];
        }
    }

    private function addHistoricalMetrics(array &$formatted, array $labels, string $query, array $historicalStats, array $percentileConfig): void {
        foreach (['above', 'below'] as $direction) {
            if (isset($historicalStats[$direction])) {
                $stats = $historicalStats[$direction];
                $prefix = ($direction === 'above') ? 'historical_upper_' : 'historical_lower_';

                $historicalDurationPercentile = $this->anomalyDetector->calculatePercentile(
                    $stats['durations'] ?? [],
                    $percentileConfig['duration'] ?? 75
                );
                $historicalSizePercentile = $this->anomalyDetector->calculatePercentile(
                    $stats['sizes'] ?? [],
                    $percentileConfig['size'] ?? 75
                );

                $metrics = [
                    'time_outside_percent' => $stats['time_outside_percent'] ?? 0,
                    'anomaly_count' => $stats['anomaly_count'] ?? 0,
                    'anomaly_duration' => !empty($stats['durations']) ? max($stats['durations']) : 0,
                    'anomaly_size' => !empty($stats['sizes']) ? max($stats['sizes']) : 0,
                    'historical_anomaly_duration' => $historicalDurationPercentile,
                    'historical_anomaly_size' => $historicalSizePercentile
                ];

                foreach ($metrics as $metricName => $metricValue) {
                    $formatted['data']['result'][] = [
                        'metric' => $this->formatMetricLabels(array_merge($labels, [
                            '__name__' => $prefix . $metricName,
                            'original_query' => $query,
                            'direction' => $direction
                        ])),
                        'values' => [[time(), (string)$metricValue]]
                    ];
                }
            }
        }
    }

    public function resampleDFT(array $dftData, int $start, int $end, int $step): array {
        $resampled = [];
        for ($currentTime = $start; $currentTime <= $end; $currentTime += $step) {
            $resampled[] = [
                'time' => $currentTime,
                'value' => $this->interpolateDFT($dftData, $currentTime)
            ];
        }
        return $resampled;
    }

    private function interpolateDFT(array $dftData, int $targetTime): float {
        $left = $right = null;
        foreach ($dftData as $point) {
            if (!isset($point['time'])) continue;
            if ($point['time'] <= $targetTime) {
                $left = $point;
            } elseif ($right === null || $point['time'] < $right['time']) {
                $right = $point;
            }
        }
        if (!$left && !$right) return 0;
        if (!$left) return $right['value'] ?? 0;
        if (!$right) return $left['value'] ?? 0;
        $deltaTime = $right['time'] - $left['time'];
        return $deltaTime == 0 ? $left['value'] ?? 0 : ($left['value'] ?? 0) + (($right['value'] ?? 0) - ($left['value'] ?? 0)) * ($targetTime - $left['time']) / $deltaTime;
    }
}
?>