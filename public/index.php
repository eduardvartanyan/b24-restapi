<?php
declare(strict_types=1);

use App\Helpers\Logger;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TgController;
use App\Http\Middleware;
use App\Services\B24Service;
use App\Services\DailyImportService;
use App\Support\Container;
use Bitrix24\SDK\Services\ServiceBuilderFactory;

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

//        case '/test':
//            echo '<pre>';
//            echo $_ENV['B24_WEBHOOK_CODE'] . PHP_EOL;
//            $b24Service = $container->get(B24Service::class);
//            $result = $b24Service->getDynamicItem(1032, [
//                'CONTACT_ID'        => 272082,
//                'PARENT_ID_2'       => 179482,
//            ]);
//            print_r($result);
    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
