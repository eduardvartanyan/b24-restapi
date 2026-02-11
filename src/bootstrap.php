<?php
declare(strict_types=1);

use App\Http\Controllers\ImportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TgController;
use App\Services\B24Service;
use App\Services\DailyImportService;
use App\Services\OneCService;
use App\Services\ReviewService;
use App\Services\TgService;
use App\Support\Container;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$container = new Container();
$container->set(B24Service::class,         fn() => new B24Service($container->get(ServiceBuilder::class)));
$container->set(ReviewService::class,      fn() => new ReviewService($container->get(B24Service::class)));
$container->set(DailyImportService::class, fn() => new DailyImportService($container->get(B24Service::class), $container->get(OneCService::class)));
$container->set(ImportController::class,   fn() => new ImportController($container->get(B24Service::class)));
$container->set(OneCService::class,        fn() => new OneCService());
$container->set(ServiceBuilder::class,     fn() => ServiceBuilderFactory::createServiceBuilderFromWebhook($_ENV['B24_WEBHOOK_CODE']));
$container->set(ReviewController::class,   fn() => new ReviewController($container->get(B24Service::class), $container->get(ReviewService::class)));
$container->set(TgService::class,          fn() => new TgService());
$container->set(TgController::class,       fn() => new TgController($container->get(TgService::class)));
