<?php
declare(strict_types=1);

use App\Helpers\Logger;
use App\Http\Controllers\BookingEventController;
use App\Http\Controllers\BookingResourceController;
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

$redactSensitiveData = static function (mixed $value) use (&$redactSensitiveData): mixed {
    if (!is_array($value)) {
        return $value;
    }

    $redacted = [];
    foreach ($value as $key => $item) {
        $normalizedKey = strtolower((string) $key);
        if (str_contains($normalizedKey, 'token') || $normalizedKey === 'authorization') {
            $redacted[$key] = '[redacted]';
            continue;
        }
        $redacted[$key] = $redactSensitiveData($item);
    }

    return $redacted;
};

Logger::info('Входящий запрос', [
    'uri'       => $uri,
    'method'    => $method,
    'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
    'query'     => $redactSensitiveData($_GET),
    'post'      => $redactSensitiveData($_POST),
    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'referer'   => $_SERVER['HTTP_REFERER'] ?? null,
]);

try {
    /** @var Container $container */

    switch ($uri) {
        case '/robots.txt':
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            readfile(__DIR__ . '/robots.txt');
            break;

        case '/api/b24/booking/events':
            if ($method === 'POST') {
                Middleware::check();
            }
            $bookingEventController = $container->get(BookingEventController::class);
            $bookingEventController->handle();
            break;

        case '/api/b24/booking/resources':
            $bookingResourceController = $container->get(BookingResourceController::class);
            $bookingResourceController->handle();
            break;

        case '/api/b24/contacts/import-birthdate':
            if ($method == 'POST') {
                Middleware::check();
                $importController = $container->get(ImportController::class);
                $importController->importBirthdate();
            }
            break;

        case '/api/b24/max/send-message':
            if ($method === 'POST') {
                Middleware::check();
            }
            $maxController = $container->get('max.controller.client');
            $maxController->handleB24MessageWebhook();
            break;

        case '/api/b24/max/send-notice':
            if ($method === 'POST') {
                Middleware::check();
            }
            $maxController = $container->get('max.controller.notice');
            $maxController->handleB24MessageWebhook();
            break;

//        case '/dtpimport':
//            $service = $container->get(DailyImportService::class);
//            $dateFrom = $_GET['date'] ?? '';
//            $service->run($dateFrom);
//            echo 'Импорт ДТП завершён успешно';
//            break;

        case '/':
            $reviewController = $container->get(ReviewController::class);
            $dealRid = $_GET['d'] ?? '';
            $contactRid = $_GET['c'] ?? '';

            if (str_contains($dealRid, '_')) {
                [$dealRid, $contactRid] = explode('_', $dealRid, 2);
            }

            $reviewController->showForm($dealRid, $contactRid);
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

        case '/api/max/notice':
            $maxController = $container->get('max.controller.notice');
            $maxController->handleNotice();
            break;

        // https://max.ru/id381250859808_bot?start=96147618
        case '/api/max':
            $maxController = $container->get('max.controller.client');
            $maxController->handle();
            break;

        // https://review.avarcomf.ru/api/max/webhook?m=mark_in_work&d=181788&c=%D0%94%D0%B5%D0%BD%D0%B8%D1%81%D1%8E%D0%BA%20%D0%95%D0%B3%D0%BE%D1%80%20%D0%A0%D0%BE%D0%BC%D0%B0%D0%BD%D0%BE%D0%B2%D0%B8%D1%87&p=+79148764102
        case '/api/max/webhook':
            $maxController = $container->get('max.controller.client');
            $maxController->handleWebhook();
            break;

        // https://review.avarcomf.ru/test
        case '/test':

            break;

    }
} catch (Throwable $e) {
    Logger::error('Необработанная ошибка входящего запроса', [
        'uri' => $uri,
        'method' => $method,
        'error_class' => $e::class,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE);
}
