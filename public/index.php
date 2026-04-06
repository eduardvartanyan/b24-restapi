<?php
declare(strict_types=1);

use App\Helpers\Logger;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MaxController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TgController;
use App\Http\Middleware;
use App\Services\B24Service;
use App\Support\Container;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/bootstrap.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

Logger::info('Входящий запрос', ['uri' => $uri, 'method' => $method, 'ip' => $_SERVER['REMOTE_ADDR']]);

try {
    /** @var Container $container */

    switch ($uri) {
        case '/api/b24/contacts/import-birthdate':
            if ($method == 'POST') {
                Middleware::check();
                $importController = $container->get(ImportController::class);
                $importController->importBirthdate();
            }
            break;

//        case '/dtpimport':
//            $service = $container->get(DailyImportService::class);
//            $dateFrom = $_GET['date'] ?? '';
//            $service->run($dateFrom);
//            echo 'Импорт ДТП завершён успешно';
//            break;

        case '/':
            $reviewController = $container->get(ReviewController::class);
            $reviewController->showForm($_GET['d'] ?? '', $_GET['c'] ?? '');
            break;

        case '/submit':
            if ($method === 'POST') {
                $controller = $container->get(ReviewController::class);
                $controller->   submit();
            }
            break;

        case '/api/tg':
            $tgController = $container->get(TgController::class);
            $tgController->handle();
            break;

        // https://max.ru/id381250859808_bot?start=96147618
        case '/api/max':
            $maxController = $container->get(MaxController::class);
            $maxController->handle();
            break;

        // https://review.avarcomf.ru/api/max/webhook?m=mark_in_work&d=181788&c=%D0%94%D0%B5%D0%BD%D0%B8%D1%81%D1%8E%D0%BA%20%D0%95%D0%B3%D0%BE%D1%80%20%D0%A0%D0%BE%D0%BC%D0%B0%D0%BD%D0%BE%D0%B2%D0%B8%D1%87&p=+79148764102
        case '/api/max/webhook':
            $maxController = $container->get(MaxController::class);
            $maxController->handleWebhook();
            break;

        // https://review.avarcomf.ru/test
        case '/test':
            echo '<pre>';
            $b24 = $container->get(B24Service::class);
            $b24->setMaxChatId(
                contactId: 124774,
                chatId: 14199860,
                source: 2526
            );

            break;

    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
