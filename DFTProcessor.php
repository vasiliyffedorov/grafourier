<?php
require_once __DIR__ . '/Logger.php';

class DFTProcessor {
    private $config;
    private $logger;

    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function generateDFT(array $bounds, int $start, int $end, int $step): array {
        $upperValues = array_column($bounds['upper'], 'value');
        $lowerValues = array_column($bounds['lower'], 'value');
        $times = array_column($bounds['upper'], 'time');

        $maxHarmonics = $this->config['corrdor_params']['max_harmonics'] ?? 10;
        $totalDuration = $end - $start;
        $numPoints = count($upperValues);

        // Вычисляем линейный тренд для верхней и нижней границы
        $upperTrend = $this->calculateLinearTrend($upperValues, $times);
        $lowerTrend = $this->calculateLinearTrend($lowerValues, $times);

        // Используем средний тренд, если включен флаг use_common_trend
        $useCommonTrend = $this->config['corrdor_params']['use_common_trend'] ?? false;
        if ($useCommonTrend) {
            $commonSlope = ($upperTrend['slope'] + $lowerTrend['slope']) / 2;
            // Корректируем intercept для сохранения индивидуальных значений границ
            $upperTrend['slope'] = $commonSlope;
            $lowerTrend['slope'] = $commonSlope;
            // Пересчитываем intercept, чтобы сохранить средние значения границ
            $upperMean = array_sum($upperValues) / $numPoints;
            $lowerMean = array_sum($lowerValues) / $numPoints;
            $meanTime = array_sum($times) / $numPoints;
            $upperTrend['intercept'] = $upperMean - $commonSlope * $meanTime;
            $lowerTrend['intercept'] = $lowerMean - $commonSlope * $meanTime;
            $this->logger->info(
                "Использован общий средний тренд: slope=$commonSlope, upper_intercept={$upperTrend['intercept']}, lower_intercept={$lowerTrend['intercept']}",
                __FILE__,
                __LINE__
            );
        }

        // Нормализуем данные, вычитая тренд
        $normalizedUpper = $this->normalizeData($upperValues, $times, $upperTrend);
        $normalizedLower = $this->normalizeData($lowerValues, $times, $lowerTrend);

        $upperCoefficients = $this->calculateDFT($normalizedUpper, $maxHarmonics, $totalDuration, $numPoints);
        $lowerCoefficients = $this->calculateDFT($normalizedLower, $maxHarmonics, $totalDuration, $numPoints);

        return [
            'upper' => [
                'coefficients' => $upperCoefficients,
                'trend' => $upperTrend
            ],
            'lower' => [
                'coefficients' => $lowerCoefficients,
                'trend' => $lowerTrend
            ]
        ];
    }

    private function calculateLinearTrend(array $values, array $times): array {
        $n = count($values);
        if ($n < 2) {
            $this->logger->warn("Недостаточно данных для вычисления тренда: $n точек", __FILE__, __LINE__);
            return ['slope' => 0, 'intercept' => $values[0] ?? 0];
        }

        // Вычисляем линейную регрессию по всем точкам
        $sumX = array_sum($times);
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumXX = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $times[$i] * $values[$i];
            $sumXX += $times[$i] * $times[$i];
        }

        $meanX = $sumX / $n;
        $meanY = $sumY / $n;
        $denominator = $sumXX - $n * $meanX * $meanX;

        if (abs($denominator) < 1e-10) {
            $this->logger->warn("Нулевой или почти нулевой знаменатель при вычислении тренда", __FILE__, __LINE__);
            return ['slope' => 0, 'intercept' => $meanY];
        }

        $slope = ($sumXY - $n * $meanX * $meanY) / $denominator;
        $intercept = $meanY - $slope * $meanX;

        $this->logger->info("Вычислен тренд: slope=$slope, intercept=$intercept", __FILE__, __LINE__);
        return ['slope' => $slope, 'intercept' => $intercept];
    }

    private function normalizeData(array $values, array $times, array $trend): array {
        $normalized = [];
        foreach ($values as $i => $value) {
            $trendValue = $trend['slope'] * $times[$i] + $trend['intercept'];
            $normalized[] = $value - $trendValue;
        }
        return $normalized;
    }

    private function calculateDFT(array $values, int $maxHarmonics, int $totalDuration, int $numPoints): array {
        $coefficients = [];
        $N = count($values);

        for ($k = 0; $k <= $N / 2; $k++) {
            $sumReal = 0;
            $sumImag = 0;
            for ($n = 0; $n < $N; $n++) {
                $angle = 2 * M_PI * $k * $n / $N;
                $sumReal += $values[$n] * cos($angle);
                $sumImag -= $values[$n] * sin($angle);
            }
            $amplitude = sqrt($sumReal * $sumReal + $sumImag * $sumImag) / ($k == 0 ? $N : $N / 2);
            $phase = ($sumReal == 0 && $sumImag == 0) ? 0 : atan2($sumImag, $sumReal);

            $coefficients[$k] = [
                'amplitude' => $amplitude,
                'phase' => $phase
            ];
        }

        $contributions = $this->calculateHarmonicContributions($coefficients, $totalDuration, $numPoints);

        $minContribution = $this->config['corrdor_params']['min_amplitude'] * $totalDuration * (2 / M_PI) ?? 1e-6;
        $filteredCoefficients = [];
        foreach ($coefficients as $k => $coeff) {
            if ($contributions[$k] >= $minContribution) {
                $filteredCoefficients[$k] = $coeff;
            }
        }

        if (!isset($filteredCoefficients[0]) && isset($coefficients[0])) {
            $filteredCoefficients[0] = $coefficients[0];
        }

        $sortedCoefficients = $filteredCoefficients;
        uasort($sortedCoefficients, function ($a, $b) use ($contributions, $filteredCoefficients) {
            $kA = array_search($a, $filteredCoefficients, true);
            $kB = array_search($b, $filteredCoefficients, true);
            return $contributions[$kB] <=> $contributions[$kA];
        });

        $selectedCoefficients = [];
        $selectedCoefficients[0] = $filteredCoefficients[0] ?? ['amplitude' => 0, 'phase' => 0];
        $count = 1;
        foreach ($sortedCoefficients as $k => $coeff) {
            if ($k != 0 && $count < $maxHarmonics) {
                $selectedCoefficients[$k] = $coeff;
                $count++;
            }
        }

        return $selectedCoefficients;
    }

    private function calculateHarmonicContributions(array $coefficients, int $totalDuration, int $numPoints): array {
        $contributions = [];
        $T = $totalDuration;
        $dt = $T / $numPoints;

        foreach ($coefficients as $k => $coeff) {
            $amplitude = $coeff['amplitude'];
            $phase = $coeff['phase'];
            $sum = 0;

            for ($i = 0; $i < $numPoints; $i++) {
                $t = $i * $dt;
                $angle = 2 * M_PI * $k * $t / $T + $phase;
                $value = $amplitude * cos($angle);
                $sum += abs($value) * $dt;
            }

            if ($k == 0) {
                $sum = $amplitude * $T;
            }

            $contributions[$k] = $sum;
        }

        return $contributions;
    }

    public function restoreFullDFT(array $coefficients, int $start, int $end, int $step, array $meta, ?array $trend = null): array {
        $dataStart = $meta['dataStart'] ?? $start;
        $totalDuration = $meta['totalDuration'] ?? ($end - $start);
        $restored = [];
        $periodSeconds = $totalDuration;

        // Восстанавливаем гармоники для всего запрошенного периода
        for ($t = $start; $t <= $end; $t += $step) {
            // Нормализуем время относительно периода данных, чтобы гармоники продолжались
            $normalizedTime = ($t - $dataStart) / $periodSeconds;
            $value = $this->calculateDFTValue($coefficients, $normalizedTime, $periodSeconds);

            // Добавляем тренд, если он предоставлен
            if ($trend !== null) {
                $trendValue = $trend['slope'] * $t + $trend['intercept'];
                $value += $trendValue;
            }

            $restored[] = [
                'time' => $t,
                'value' => $value
            ];
        }

        return $restored;
    }

    private function calculateDFTValue(array $coefficients, float $normalizedTime, int $periodSeconds): float {
        $value = 0;
        $baseAmplitude = $coefficients[0]['amplitude'] ?? 0;

        foreach ($coefficients as $harmonic => $coeff) {
            if ($harmonic == 0) {
                $value += $coeff['amplitude'];
                continue;
            }
            $frequency = $harmonic;
            $angle = 2 * M_PI * $frequency * $normalizedTime + $coeff['phase'];
            $contribution = $coeff['amplitude'] * cos($angle);
            $value += $contribution;
        }

        return $value;
    }
}