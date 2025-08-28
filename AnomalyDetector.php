<?php
require_once __DIR__ . '/Logger.php';

class AnomalyDetector
{
    private $config;
    private $logger;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Рассчитывает статистику аномалий.
     *
     * @param array      $dataPoints        Сырые точки [ ['time'=>int, 'value'=>float], ... ]
     * @param array      $upperBound        Коридор сверху
     * @param array      $lowerBound        Коридор снизу
     * @param array|null $percentileConfig  Конфиг перцентилей (только для истории)
     * @param bool       $raw               Если true — вернуть сырые массивы длительностей/размеров
     *
     * @return array{above: array, below: array, combined: array}
     */
    public function calculateAnomalyStats(
        array $dataPoints,
        array $upperBound,
        array $lowerBound,
        ?array $percentileConfig = null,
        bool $raw = false
    ): array {
        if (empty($dataPoints) || empty($upperBound) || empty($lowerBound)) {
            $this->logger->warn(
                "Недостаточно данных для расчёта статистики аномалий: "
                . "dataPoints=" . count($dataPoints)
                . ", upperBound=" . count($upperBound)
                . ", lowerBound=" . count($lowerBound),
                __FILE__, __LINE__
            );
            $zeroStats = [
                'time_outside_percent' => 0,
                'anomaly_count'        => 0,
                'durations'            => [],
                'sizes'                => [],
                'direction'            => ''
            ];
            return [
                'above'    => array_merge($zeroStats, ['direction'=>'above']),
                'below'    => array_merge($zeroStats, ['direction'=>'below']),
                'combined' => [
                    'time_outside_percent' => 0,
                    'anomaly_count'        => 0
                ]
            ];
        }

        usort($dataPoints, fn($a, $b) => $a['time'] <=> $b['time']);

        $statsAbove = $this->calculateDirectionalStats(
            $dataPoints, $upperBound, 'above', $raw
        );
        $statsBelow = $this->calculateDirectionalStats(
            $dataPoints, $lowerBound, 'below', $raw
        );

        $combinedTime  = $statsAbove['time_outside_percent'] + $statsBelow['time_outside_percent'];
        $combinedCount = $statsAbove['anomaly_count'] + $statsBelow['anomaly_count'];

        return [
            'above'    => $statsAbove,
            'below'    => $statsBelow,
            'combined' => [
                'time_outside_percent' => round($combinedTime, 2),
                'anomaly_count'        => $combinedCount
            ]
        ];
    }

    /**
     * Вычисляет stats для направления above/below.
     *
     * @param array  $dataPoints  Отсортированные точки
     * @param array  $boundary    Коридор (DFT)
     * @param string $direction   'above' или 'below'
     * @param bool   $raw         true — возвращать сырые массивы
     *
     * @return array{
     *   time_outside_percent: float,
     *   anomaly_count: int,
     *   durations: float[],
     *   sizes: float[],
     *   direction: string
     * }
     */
    private function calculateDirectionalStats(
        array $dataPoints,
        array $boundary,
        string $direction,
        bool $raw
    ): array {
        $timeOutside        = 0;
        $anomalyCount       = 0;
        $currentAnomalyStart = null;
        $prevTime           = $dataPoints[0]['time'];
        $totalRange         = end($dataPoints)['time'] - $dataPoints[0]['time'];

        $durations = [];
        $sizes     = [];

        foreach ($dataPoints as $idx => $pt) {
            $t      = $pt['time'];
            $v      = $pt['value'];
            $b      = $this->interpolateDFT($boundary, $t);
            $isAnom = ($direction === 'above') ? ($v > $b) : ($v < $b);

            if ($isAnom) {
                if ($currentAnomalyStart === null) {
                    $currentAnomalyStart = $prevTime;
                }
                $dur = $t - $currentAnomalyStart;
                $sz  = round(abs($v - $b) / max(1, $b) * 100, 2);

                $durations[] = $dur;
                $sizes[]     = $sz;
                $anomalyCount++;

                if ($idx === count($dataPoints) - 1) {
                    $timeOutside += $dur;
                }
            } elseif ($currentAnomalyStart !== null) {
                $dur = $t - $currentAnomalyStart;
                $durations[]  = $dur;
                $timeOutside += $dur;
                $currentAnomalyStart = null;
            }

            $prevTime = $t;
        }

        $timePct = $totalRange > 0 ? ($timeOutside / $totalRange) * 100 : 0;

        if ($raw) {
            sort($durations);
            sort($sizes);
        } else {
            $percentiles = $this->config['cache']['percentiles'] ?? [];
            $cntP = count($percentiles);

            if (count($durations) <= $cntP) {
                sort($durations);
                $durations = array_merge(
                    $durations,
                    array_fill(count($durations), $cntP - count($durations), 0.00)
                );
            } else {
                $durations = $this->calculatePercentileValues($durations, $percentiles);
            }

            if (count($sizes) <= $cntP) {
                sort($sizes);
                $sizes = array_merge(
                    $sizes,
                    array_fill(count($sizes), $cntP - count($sizes), 0.00)
                );
            } else {
                $sizes = $this->calculatePercentileValues($sizes, $percentiles);
            }
        }

        return [
            'time_outside_percent' => round($timePct, 2),
            'anomaly_count'        => $anomalyCount,
            'durations'            => $durations,
            'sizes'                => $sizes,
            'direction'            => $direction
        ];
    }

    /**
     * Вычисляет заданные перцентили из массива значений.
     *
     * @param float[] $values
     * @param int[]   $percentiles
     * @return float[]
     */
    private function calculatePercentileValues(array $values, array $percentiles): array
    {
        $res = array_fill(0, count($percentiles), 0.00);
        if (empty($values)) {
            return $res;
        }
        sort($values);
        $n = count($values);

        foreach ($percentiles as $i => $p) {
            if ($n === 1) {
                $res[$i] = $p > 0 ? round($values[0], 2) : 0.00;
            } else {
                $idx  = ($p / 100) * ($n - 1);
                $lo   = floor($idx);
                $frac = $idx - $lo;
                if ($lo >= $n - 1) {
                    $res[$i] = round($values[$n - 1], 2);
                } else {
                    $res[$i] = round(
                        $values[$lo] + $frac * ($values[$lo + 1] - $values[$lo]),
                        2
                    );
                }
            }
        }

        return $res;
    }

    /**
     * Стандартный расчет одиночного перцентиля.
     */
    public function calculatePercentile(array $values, float $percentile): float
    {
        if (empty($values)) {
            return 0;
        }
        $values = array_filter($values, fn($v) => $v > 0);
        if (empty($values)) {
            return 0;
        }
        sort($values);
        $count = count($values);
        $index = ($percentile / 100) * ($count - 1);
        $floor = floor($index);
        $frac  = $index - $floor;
        if ($floor >= $count - 1) {
            return $values[$count - 1];
        }
        return $values[$floor] + $frac * ($values[$floor + 1] - $values[$floor]);
    }

    /**
     * Линейная интерполяция DFT-выхода.
     */
    private function interpolateDFT(array $dftData, int $targetTime): float
    {
        $left = $right = null;
        foreach ($dftData as $point) {
            if (!isset($point['time'])) {
                continue;
            }
            if ($point['time'] <= $targetTime) {
                $left = $point;
            } elseif ($right === null || $point['time'] < $right['time']) {
                $right = $point;
            }
        }
        if (!$left && !$right) {
            return 0;
        }
        if (!$left) {
            return $right['value'] ?? 0;
        }
        if (!$right) {
            return $left['value'] ?? 0;
        }
        $dt = $right['time'] - $left['time'];
        return $dt === 0
            ? ($left['value'] ?? 0)
            : ($left['value'] + (($right['value'] - $left['value']) * ($targetTime - $left['time']) / $dt));
    }

    /**
     * Интегральная метрика «concern» для одного направления.
     */
    public function calculateIntegralMetric(array $currentStats, array $historicalStats): float
    {
        if (empty($historicalStats) ||
            empty($currentStats['durations']) ||
            empty($currentStats['sizes'])) {
            return 0;
        }

        $historicalDuration = $this->calculatePercentile(
            $historicalStats['durations'] ?? [],
            $this->config['corrdor_params']['default_percentiles']['duration']
        );
        $historicalSize = $this->calculatePercentile(
            $historicalStats['sizes'] ?? [],
            $this->config['corrdor_params']['default_percentiles']['size']
        );

        if ($historicalDuration == 0 || $historicalSize == 0) {
            return 0;
        }

        $durationMultiplier = $this->config['corrdor_params']['default_percentiles']['duration_multiplier'] ?? 1.0;
        $sizeMultiplier     = $this->config['corrdor_params']['default_percentiles']['size_multiplier'] ?? 1.0;

        $historicalArea   = $historicalDuration * $historicalSize;
        $currentDuration  = !empty($currentStats['durations'])
            ? max($currentStats['durations']) * $durationMultiplier
            : 0;
        $currentSize      = !empty($currentStats['sizes'])
            ? max($currentStats['sizes']) * $sizeMultiplier
            : 0;

        $this->logger->info(
            "Applied multipliers: duration_multiplier=$durationMultiplier (duration=$currentDuration), "
            . "size_multiplier=$sizeMultiplier (size=$currentSize)",
            __FILE__, __LINE__
        );

        if ($currentDuration == 0 || $currentSize == 0) {
            return 0;
        }

        $ratio      = exp(($currentDuration * $currentSize) / $historicalArea);
        $normalized = min(10, $ratio) / 10;
        return $normalized;
    }

    /**
     * Интегральная метрика «concern sum» (сумма всех аномалий).
     */
    public function calculateIntegralMetricSum(array $currentStats, array $historicalStats, int $windowSize): float
    {
        if (empty($historicalStats) ||
            empty($currentStats['durations']) ||
            empty($currentStats['sizes'])) {
            return 0;
        }

        $adjustedHistorical = $this->adjustPercentile($currentStats, $historicalStats, $windowSize);

        $historicalDuration = $this->calculatePercentile(
            $adjustedHistorical['durations'] ?? [],
            $this->config['corrdor_params']['default_percentiles']['duration']
        );
        $historicalSize = $this->calculatePercentile(
            $adjustedHistorical['sizes'] ?? [],
            $this->config['corrdor_params']['default_percentiles']['size']
        );

        if ($historicalDuration == 0 || $historicalSize == 0) {
            return 0;
        }

        $durationMultiplier = $this->config['corrdor_params']['default_percentiles']['duration_multiplier'] ?? 1.0;
        $sizeMultiplier     = $this->config['corrdor_params']['default_percentiles']['size_multiplier'] ?? 1.0;

        $historicalArea   = $historicalDuration * $historicalSize;
        $currentTotalArea = array_sum(array_map(
            fn($d, $s) => ($d * $durationMultiplier) * ($s * $sizeMultiplier),
            $currentStats['durations'],
            $currentStats['sizes']
        ));

        $this->logger->info(
            "Applied multipliers in sum: duration_multiplier=$durationMultiplier, "
            . "size_multiplier=$sizeMultiplier, total_area=$currentTotalArea",
            __FILE__, __LINE__
        );

        if ($currentTotalArea == 0) {
            return 0;
        }

        $ratio      = exp($currentTotalArea / $historicalArea);
        $normalized = min(10, $ratio) / 10;
        return $normalized;
    }

    /**
     * Подгон исторических перцентилей под окно windowSize.
     */
    private function adjustPercentile(array $currentStats, array $historicalStats, int $windowSize): array
    {
        $adjusted = $historicalStats;
        $histDur = $this->calculatePercentile(
            $historicalStats['durations'] ?? [],
            $this->config['corrdor_params']['default_percentiles']['duration']
        );
        if ($windowSize < $histDur) {
            $newMax = min($histDur, $windowSize / 2);
            $adjusted['durations'] = array_map(
                fn($v) => min($v, $newMax),
                $historicalStats['durations']
            );
        }
        return $adjusted;
    }
}
