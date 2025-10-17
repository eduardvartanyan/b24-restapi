<?php
declare(strict_types=1);

namespace App\Helpers;

class Logger
{
    private static function writeLog(string $level, string $message, array $context): void
    {
        $date = date('Y-m-d');
        $time = date('H:i:s');
        $filePath = __DIR__ . "/../../storage/logs/app-{$date}.log";

        $contextString = !empty($context)
            ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : '';

        $logMessage = "[{$time}] {$level}: {$message} {$contextString}\n";

        echo $filePath . PHP_EOL;

        $result = file_put_contents($filePath, $logMessage, FILE_APPEND);
        var_dump($result);
    }

    public static function info(string $message, array $context = []): void
    {
        self::writeLog('INFO', $message, $context);
    }
    public static function error(string $message, array $context = []): void
    {
        self::writeLog('ERROR', $message, $context);
    }
}