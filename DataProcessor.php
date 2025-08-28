<?php
require_once __DIR__ . '/Logger.php';

class DataProcessor {
    private $config;
    private $logger;

    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function updateConfig(array $config): void {
        $this->config = $config;
    }

    public function groupData(array $rawData): array {
        $groupedData = [];
        foreach ($rawData as $point) {
            $labels = $point['labels'] ?? [];
            unset($labels['__name__']);
            ksort($labels);
            $labelsJson = json_encode($labels);
            $groupedData[$labelsJson][] = [
                'time' => strtotime($point['time']),
                'value' => (float)$point['value']
            ];
        }
        return $groupedData;
    }

    public function getActualDataRange(array $data, ?int $defaultStart = null, ?int $defaultEnd = null): array {
        if (empty($data)) {
            $this->logger->warn("Пустые данные для определения диапазона", __FILE__, __LINE__);
            return [
                'start' => $defaultStart ?? time() - 86400,
                'end' => $defaultEnd ?? time()
            ];
        }

        $times = array_column($data, 'time');
        $start = min($times);
        $end = max($times);

        return [
            'start' => $start,
            'end' => $end
        ];
    }

    public function calculateBounds(array $data, int $start, int $end, int $step): array {
        if (empty($data)) {
            $this->logger->warn("Пустые данные для расчета границ", __FILE__, __LINE__);
            return ['upper' => [], 'lower' => []];
        }

        // Интерполируем данные для обеспечения кратности шага
        $interpolatedData = $this->interpolateData($data, $start, $end, $step);
        $values = array_column($interpolatedData, 'value');
        $times = array_column($interpolatedData, 'time');

        // Проверяем конфигурацию
        if (!is_array($this->config['corrdor_params']) ||
            !isset($this->config['corrdor_params']['window_size']) ||
            !isset($this->config['corrdor_params']['margin_percent'])) {
            $this->logger->error("Неверная конфигурация метода adaptive", __FILE__, __LINE__);
            throw new Exception("Неверная конфигурация метода adaptive");
        }

        $windowSize = $this->config['corrdor_params']['window_size'];
        $marginPercent = $this->config['corrdor_params']['margin_percent'] ?? 10;

        // Рассчитываем скользящее среднее и границы
        $upper = [];
        $lower = [];
        $halfWindow = (int)($windowSize / 2);

        for ($i = 0; $i < count($values); $i++) {
            $startIdx = max(0, $i - $halfWindow);
            $endIdx = min(count($values) - 1, $i + $halfWindow);
            $window = array_slice($values, $startIdx, $endIdx - $startIdx + 1);

            // Скользящее среднее
            $avg = array_sum($window) / count($window);
            // Максимум и минимум в окне
            $maxValue = max($window);
            $minValue = min($window);
            // Добавляем отступ (margin) к границам
            $margin = $avg * ($marginPercent / 100);
            $upperValue = $maxValue + $margin;
            $lowerValue = $minValue - $margin;

            $upper[] = ['time' => $times[$i], 'value' => $upperValue];
            $lower[] = ['time' => $times[$i], 'value' => $lowerValue];
        }

        return [
            'upper' => $upper,
            'lower' => $lower
        ];
    }

    private function interpolateData(array $data, int $start, int $end, int $step): array {
        $interpolated = [];
        usort($data, fn($a, $b) => $a['time'] <=> $b['time']);
        for ($t = $start; $t <= $end; $t += $step) {
            $value = $this->interpolateValue($data, $t);
            $interpolated[] = ['time' => $t, 'value' => $value];
        }
        return $interpolated;
    }

    private function interpolateValue(array $data, int $targetTime): float {
        $left = $right = null;
        foreach ($data as $point) {
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
        return $deltaTime == 0 ? $left['value'] : $left['value'] + ($right['value'] - $left['value']) * ($targetTime - $left['time']) / $deltaTime;
    }
}
?>