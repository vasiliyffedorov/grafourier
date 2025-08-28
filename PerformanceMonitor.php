<?php
class PerformanceMonitor {
    private static $enabled = true;
    private static $thresholdMs = 5.0;
    private static $timings = [];
    private static $startTimes = [];
    private static $memoryPeak = 0;

    public static function init(bool $enabled, float $thresholdMs): void {
        self::$enabled = $enabled;
        self::$thresholdMs = $thresholdMs;
    }

    public static function reset(): void {
        self::$timings = [];
        self::$startTimes = [];
        self::$memoryPeak = memory_get_peak_usage(true) / 1024 / 1024;
    }

    public static function start(string $name): void {
        if (!self::$enabled) return;
        self::$startTimes[$name] = microtime(true);
    }

    public static function end(string $name): void {
        if (!self::$enabled || !isset(self::$startTimes[$name])) return;

        $duration = (microtime(true) - self::$startTimes[$name]) * 1000;
        self::$timings[$name] = round($duration, 2);
        unset(self::$startTimes[$name]);

        if ($duration > self::$thresholdMs) {
            global $logger;
            if ($logger instanceof Logger) {
                $logger->warn("Slow operation: $name took {$duration}ms", __FILE__, __LINE__);
            }
        }
    }

    public static function getDuration(string $name): float {
        return self::$timings[$name] ?? 0.0;
    }

    public static function getMetrics(): array {
        return [
            'timings' => self::$timings,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ];
    }
}