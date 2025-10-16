<?php
declare(strict_types=1);

use App\Http\Controllers\ImportController;
use App\Http\Middleware;
use App\Services\B24Service;
use App\Support\Container;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/bootstrap.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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

        case '/test':
            $b24Service = $container->get(B24Service::class);
            $b24Service->importBirthdate([
                [
                    'name' => 'ВАРТАНЯН ЭДУАРД',
                    'phone' => '+79027611122',
                    'birthdate' => '23.07.1989'
                ],
            ]);
    }
} catch (ReflectionException $e) {
    echo $e->getMessage();
}