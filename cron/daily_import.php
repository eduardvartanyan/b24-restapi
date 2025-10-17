<?php
declare(strict_types=1);

use App\Helpers\Logger;
use App\Services\DailyImportService;
use App\Support\Container;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/bootstrap.php';

Logger::info('--- Ежедневный импорт ДТП начат ---');

/** @var Container $container */
try {
    $service = $container->get(DailyImportService::class);
    $service->run();
    Logger::info('--- Импорт ДТП завершён успешно ---');
} catch (Throwable $e) {
    Logger::error('Ошибка при импорте ДТП', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}
