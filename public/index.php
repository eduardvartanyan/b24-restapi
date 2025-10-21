<?php
declare(strict_types=1);

use App\Helpers\Logger;
use App\Http\Controllers\ImportController;
use App\Http\Middleware;
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

        case '/dtpimport':
            $service = $container->get(DailyImportService::class);
            $dateFrom = $_GET['date'] ?? '';
            $service->run($dateFrom);
            echo 'Импорт ДТП завершён успешно';
            break;

        case '/test':
            $b24 = ServiceBuilderFactory::createServiceBuilderFromWebhook($_ENV['B24_WEBHOOK_CODE']);
            $deal = $b24->getCRMScope()->deal()->list(
                [],
                [
                    'UF_CRM_1561010424' => '143930',
                    'UF_CRM_1574325151082' => '89501062368'
                ],
                ['ID']
            );
            var_dump($deal->getCoreResponse()->getResponseData()->getResult());
    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
