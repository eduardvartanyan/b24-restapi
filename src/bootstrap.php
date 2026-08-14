<?php
declare(strict_types=1);

use App\Http\Controllers\ImportController;
use App\Http\Controllers\BookingEventController;
use App\Http\Controllers\MaxController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TgController;
use App\Booking\BookingAutomationConfig;
use App\Contracts\BookingAutomationGateway;
use App\Repositories\ChatRequestRepository;
use App\Repositories\ChatSourceRepository;
use App\Repositories\ChatStateRepository;
use App\Repositories\ClickRepository;
use App\Handlers\BookingEventHandler;
use App\Services\B24Service;
use App\Services\Bitrix24BookingAutomationGateway;
use App\Services\DaDataService;
use App\Services\DailyImportService;
use App\Services\MaxService;
use App\Services\OneCService;
use App\Services\ReviewService;
use App\Services\TgService;
use App\Support\Container;
use App\Support\MessageCatalog;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$container = new Container();
$container->set(BookingAutomationConfig::class, fn() => BookingAutomationConfig::fromEnvironment($_ENV));
$container->set(BookingAutomationGateway::class, fn() => new Bitrix24BookingAutomationGateway(
    $container->get(ServiceBuilder::class),
    $container->get(BookingAutomationConfig::class),
    $container->get(MaxService::class),
));
$container->set(BookingEventHandler::class,      fn() => new BookingEventHandler(
    $container->get(BookingAutomationGateway::class),
    $container->get(BookingAutomationConfig::class),
));
$container->set(BookingEventController::class,   fn() => new BookingEventController(
    $container->get(BookingEventHandler::class)
));
$container->set(B24Service::class,              fn() => new B24Service($container->get(ServiceBuilder::class)));
$container->set(ReviewService::class,           fn() => new ReviewService(
    $container->get(B24Service::class),
    $container->get(ClickRepository::class)
));
$container->set(DailyImportService::class,      fn() => new DailyImportService(
    $container->get(B24Service::class),
    $container->get(OneCService::class)
));
$container->set(ImportController::class,        fn() => new ImportController($container->get(B24Service::class)));
$container->set(OneCService::class,             fn() => new OneCService());
$container->set(ServiceBuilder::class,          fn() => ServiceBuilderFactory::createServiceBuilderFromWebhook(
    $_ENV['B24_WEBHOOK_CODE']
));
$container->set(ReviewController::class,        fn() => new ReviewController(
    $container->get(B24Service::class),
    $container->get(ReviewService::class)
));
$container->set(TgService::class,               fn() => new TgService());
$container->set(TgController::class,            fn() => new TgController($container->get(TgService::class)));
$container->set(MaxController::class,           fn() => new MaxController($container->get(MaxService::class)));
$container->set(ClickRepository::class,         fn() => new ClickRepository());
$container->set(MaxService::class,              fn() => new MaxService(
    $container->get(B24Service::class),
    $container->get(PHPMaxBot::class),
    $container->get(ChatStateRepository::class),
    $container->get(ChatRequestRepository::class),
    $container->get(ChatSourceRepository::class),
    $container->get(DaDataService::class),
    new MessageCatalog(__DIR__ . '/Support/Messages/chatbot.php')
));
$container->set(PHPMaxBot::class,               fn() => new PHPMaxBot($_ENV['MAX_BOT_TOKEN']));
$container->set(ChatStateRepository::class,     fn() => new ChatStateRepository());
$container->set(ChatRequestRepository::class,   fn() => new ChatRequestRepository());
$container->set(ChatSourceRepository::class,    fn() => new ChatSourceRepository());
$container->set(DaDataService::class,           fn() => new DaDataService(
    $_ENV['DADATA_TOKEN'],
    $_ENV['DADATA_SECRET']
));
