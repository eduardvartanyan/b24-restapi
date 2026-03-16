<?php
declare(strict_types=1);

use App\Helpers\Logger;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MaxController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TgController;
use App\Http\Middleware;
use App\Repositories\ChatRequestRepository;
use App\Services\B24Service;
use App\Services\DaDataService;
use App\Services\DailyImportService;
use App\Support\Container;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use PHPMaxBot;

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
                $controller->submit();
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

        // https://review.avarcomf.ru/test
        case '/test':
            echo '<pre>';
            $result = mb_strtoupper(str_replace(
                    ['г Иркутск', ' ул ', ', д '],
                    ['г. Иркутск', ' ул. ', ' '],
                    'г Иркутск, ул Степана Разина, д 12'
            ));
            var_dump($result);

    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
