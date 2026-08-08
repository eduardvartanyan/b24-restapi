<?php
declare(strict_types=1);

use App\Helpers\Logger;
use App\Http\Controllers\BookingEventController;
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
        case '/api/b24/booking/events':
            if ($method === 'POST') {
                Middleware::check();
            }
            $bookingEventController = $container->get(BookingEventController::class);
            $bookingEventController->handle();
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
            $maxController = $container->get(MaxController::class);
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
            die();
            $dealRids = [
//                '36357505', '47937510', '83840691', '51212549', '72777756', '53218612', '65430314', '27203975',
//                '24058388', '91836596', '52247372', '90431312', '89456372', '82983847', '59870157', '27201539',
//                '13954911', '40361019', '78220708', '75597580', '38732597', '81592585', '92263646', '81379812',
//                '60577661', '67939567', '19908297', '72359565', '95468723', '41893791', '30022394', '90075914',
//                '42473631', '86851223', '47348560', '78764468', '12635205', '40693132', '12667227', '11193355',
//                '64838012', '68020431', '27884023', '31418333', '93332984', '48881939', '23554523', '28667760',
//                '80632345', '63834598', '92632041', '23490319', '39778490', '76195098', '14214589', '56467712',
//                '45360996', '15850668', '22077843', '85759169', '97447127', '28267295', '69159604', '78370537',
//                '42364808', '50640636', '84125447', '14781909', '26751064', '37902664', '63857949', '30116893',
//                '86558821', '35007990', '76839474', '99405021', '49542166', '57514732', '56168356', '85254151',
//                '39503154', '88681276', '56168356', '65630470', '36664289', '25647405', '90445144', '65630470',
//                '17808846', '93072403', '33085338', '24853586', '77509024', '24853586', '75490925', '72994794',
//                '22091150', '54284348', '84616809', '50836210', '46587596', '18541387', '95953396', '29272214',
//                '75476942', '26241447', '31404076', '18361569', '46617192', '38781393', '87235209', '90472816',
//                '12076282', '10818463', '30178741', '34979722', '77340655', '48380832', '66553687', '82359884',
//                '89711309', '14392354', '83660745', '62702363', '77329184', '70756534', '72202785', '55984701',
//                '48867882', '77329184', '20758061', '87611885', '55028824', '64007269', '18226855', '64007269',
//                '21393237', '30398151', '17660987', '21716292', '75486727', '76384157', '71014376', '85286894',
//                '40331917', '50645585', '94918383', '33532744', '17719327', '64804694', '88677414', '82811969',
//                '97503939', '23757291', '84122207', '91208541', '36329003', '74590363', '29708878', '49893284',
//                '93467128', '54901251', '79083725', '43088160', '76383409', '57608775', '13116108', '32790812',
//                '80412607', '96141764', '32899529', '40168837', '28368547', '14363699', '14070628', '73716088',
//                '62586373', '31332692', '12722998', '18574891', '76427426', '31882607', '26466440', '23031068',
//                '16870594', '51459453', '76021606', '89879720', '15633288', '58579723', '52073175', '69532247',
//                '55506400', '91497734', '18516504', '54328554', '25565910', '48554733', '86369915', '88540182',
//                '81190611', '41767149', '53442527', '75395209', '88291527', '65740144', '69648905', '89936764',
//                '86218298', '16959105', '27863612', '76533158', '52135850', '64890398', '32905732', '76807211',
                '80251397', '29277341', '29671188', '96643893', '99969094',
            ];

            $b24 = $container->get(B24Service::class);
            $dealIdsByRid = [];
            $dealContactsById = [];
            $contactRidsById = [];
            $reviewIdsByDealContact = [];
            $workflowResultsByDealContact = [];

            echo '<pre>';
            echo 'RID сделки => ID сделки => ID контакта => RID контакта => отзыв => бизнес-процесс' . PHP_EOL;
            echo str_repeat('-', 105) . PHP_EOL;

            foreach ($dealRids as $dealRid) {
                if (!array_key_exists($dealRid, $dealIdsByRid)) {
                    $dealIdsByRid[$dealRid] = $b24->getDealIdByRid($dealRid);
                }

                $dealId = $dealIdsByRid[$dealRid];
                if ($dealId && !array_key_exists($dealId, $dealContactsById)) {
                    $response = $b24->sendCurl('crm.deal.contact.items.get', [
                        'id' => $dealId,
                    ]);
                    $dealContactsById[$dealId] = $response['result'] ?? [];
                }

                $contacts = array_map(
                    static fn(array $contact): string => (string) ($contact['CONTACT_ID'] ?? ''),
                    $dealContactsById[$dealId] ?? []
                );
                $contacts = array_filter($contacts);

                if (!$contacts) {
                    echo htmlspecialchars($dealRid)
                        . ' => '
                        . htmlspecialchars((string) ($dealId ?? 'не найдена'))
                        . ' => контакты не найдены'
                        . PHP_EOL;
                    continue;
                }

                foreach ($contacts as $contactId) {
                    if (!array_key_exists($contactId, $contactRidsById)) {
                        $response = $b24->sendCurl('crm.contact.get', [
                            'id' => $contactId,
                        ]);
                        $contactRidsById[$contactId] = $response['result']['UF_CRM_1764653531'] ?? null;
                    }

                    $reviewKey = $dealId . ':' . $contactId;
                    if (!array_key_exists($reviewKey, $reviewIdsByDealContact)) {
                        $response = $b24->sendCurl('crm.item.list', [
                            'entityTypeId'        => 1032,
                            'filter[CONTACT_ID]'  => $contactId,
                            'filter[PARENT_ID_2]' => $dealId,
                            'select[0]'           => 'id',
                        ]);
                        $reviewIdsByDealContact[$reviewKey] = $response['result']['items'][0]['id'] ?? null;
                    }

                    if (!array_key_exists($reviewKey, $workflowResultsByDealContact)) {
                        if ($reviewIdsByDealContact[$reviewKey]) {
                            $workflowResultsByDealContact[$reviewKey] = 'пропущен: отзыв уже есть';
                        } else {
                            $response = $b24->sendCurl('bizproc.workflow.start', [
                                'TEMPLATE_ID' => 690,
                                'DOCUMENT_ID' => [
                                    'crm',
                                    'CCrmDocumentContact',
                                    'CONTACT_' . $contactId,
                                ],
                                'PARAMETERS' => [
                                    'dealRid' => $dealRid,
                                ],
                            ]);

                            $workflowResultsByDealContact[$reviewKey] = $response['result']
                                ?? ($response['error_description'] ?? $response['error'] ?? 'ошибка запуска');
                        }
                    }

                    echo htmlspecialchars($dealRid)
                        . ' => '
                        . htmlspecialchars((string) $dealId)
                        . ' => '
                        . htmlspecialchars($contactId)
                        . ' => '
                        . htmlspecialchars((string) ($contactRidsById[$contactId] ?? 'RID контакта не найден'))
                        . ' => '
                        . htmlspecialchars($reviewIdsByDealContact[$reviewKey] ? 'отзыв #' . $reviewIdsByDealContact[$reviewKey] : 'нет отзыва')
                        . ' => '
                        . htmlspecialchars((string) $workflowResultsByDealContact[$reviewKey])
                        . PHP_EOL;
                }
            }

            echo '</pre>';

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
