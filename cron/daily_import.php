<?php
declare(strict_types=1);

use App\Helpers\Logger;
use App\Services\DailyImportService;
use App\Support\Container;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/bootstrap.php';

/** @var Container $container */
try {
    $service = $container->get(DailyImportService::class);
    $service->run();
    echo date('Y-m-d H:i:s') . ' Импорт ДТП завершён успешно' . PHP_EOL;
} catch (Throwable $e) {
    Logger::error('Ошибка при импорте ДТП', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    echo date('Y-m-d H:i:s') . ' Импорт ДТП завершён с ошибкой' . PHP_EOL;
}
