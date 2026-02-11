<?php
declare(strict_types=1);

use App\Helpers\Logger;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ReviewController;
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
            if ($method !== 'POST') {
                http_response_code(405);
                echo 'Method Not Allowed';
                break;
            }

            $raw = file_get_contents('php://input') ?: '';
            $payload = json_decode($raw, true);

            if (!is_array($payload)) {
                Logger::error('TG webhook: invalid JSON', ['raw' => $raw]);
                http_response_code(400);
                echo 'Invalid payload';
                break;
            }

            $message = $payload['message'] ?? $payload['edited_message'] ?? null;
            $from = $message['from'] ?? [];
            $chat = $message['chat'] ?? [];

            Logger::info('TG webhook: incoming update', [
                'update_id' => $payload['update_id'] ?? null,
                'message_id' => $message['message_id'] ?? null,
                'date' => $message['date'] ?? null,
                'from' => [
                    'id' => $from['id'] ?? null,
                    'username' => $from['username'] ?? null,
                    'first_name' => $from['first_name'] ?? null,
                    'last_name' => $from['last_name'] ?? null,
                    'is_bot' => $from['is_bot'] ?? null,
                ],
                'chat' => [
                    'id' => $chat['id'] ?? null,
                    'type' => $chat['type'] ?? null,
                    'title' => $chat['title'] ?? null,
                    'username' => $chat['username'] ?? null,
                ],
                'text' => $message['text'] ?? null,
                'payload' => $payload,
            ]);

            http_response_code(200);
            echo 'OK';
            break;

//        case '/test':
//            $b24 = ServiceBuilderFactory::createServiceBuilderFromWebhook($_ENV['B24_WEBHOOK_CODE']);
//            $deal = $b24->getCRMScope()->deal()->list(
//                [],
//                [
//                    'UF_CRM_1561010424' => '143930',
//                    'UF_CRM_1574325151082' => '89501062368'
//                ],
//                ['ID']
//            );
//            var_dump($deal->getCoreResponse()->getResponseData()->getResult());
    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
