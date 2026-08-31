<?php
declare(strict_types=1);

use App\Contracts\BookingResourceProvider;
use App\Http\Controllers\BookingResourceController;

require_once __DIR__ . '/../vendor/autoload.php';

final readonly class FakeBookingResourceProvider implements BookingResourceProvider
{
    public function __construct(private array $resources)
    {
    }

    public function listResources(): array
    {
        return $this->resources;
    }
}

function assertBookingResourceSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$controller = new BookingResourceController(new FakeBookingResourceProvider([
    ['id' => 17, 'name' => "Иван\nИванов"],
    ['id' => 4, 'name' => 'Петр Петров'],
]));

ob_start();
$controller->handle();
$body = (string) ob_get_clean();

assertBookingResourceSame(
    "Иван Иванов – 17\nПетр Петров – 4\n",
    $body,
    'Resource list has an unexpected public representation',
);
assertBookingResourceSame(200, http_response_code(), 'GET must return HTTP 200');

$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
$controller->handle();
$body = (string) ob_get_clean();

assertBookingResourceSame('Method Not Allowed', $body, 'POST must be rejected');
assertBookingResourceSame(405, http_response_code(), 'POST must return HTTP 405');

echo "BookingResourceController tests passed\n";
