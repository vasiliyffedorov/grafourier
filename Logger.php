<?php
class Logger {
    private $filePath;
    private $logLevel;

    const LEVEL_INFO = 1;
    const LEVEL_WARN = 2;
    const LEVEL_ERROR = 3;

    public function __construct(string $filePath, int $logLevel = self::LEVEL_INFO) {
        $this->filePath = $filePath;
        $this->logLevel = $logLevel;
    }

    public function log(string $message, string $file, int $line, int $level = self::LEVEL_INFO): void {
        if ($level < $this->logLevel) {
            return;
        }

        $levelStr = match ($level) {
            self::LEVEL_INFO => 'INFO',
            self::LEVEL_WARN => 'WARN',
            self::LEVEL_ERROR => 'ERROR',
            default => 'UNKNOWN'
        };

        $entry = sprintf("[%s] %s %s:%d | %s\n",
            date('Y-m-d H:i:s'),
            $levelStr,
            basename($file),
            $line,
            $message
        );
        file_put_contents($this->filePath, $entry, FILE_APPEND);
    }

    public function info(string $message, string $file, int $line): void {
        $this->log($message, $file, $line, self::LEVEL_INFO);
    }

    public function warn(string $message, string $file, int $line): void {
        $this->log($message, $file, $line, self::LEVEL_WARN);
    }

    public function error(string $message, string $file, int $line): void {
        $this->log($message, $file, $line, self::LEVEL_ERROR);
    }
}
?>