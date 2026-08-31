<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\BookingResourceProvider;
use App\Helpers\Logger;
use Throwable;

final readonly class BookingResourceController
{
    public function __construct(private BookingResourceProvider $resources)
    {
    }

    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $this->textResponse(405, 'Method Not Allowed', ['Allow' => 'GET']);
            return;
        }

        $previousErrorReporting = error_reporting();
        error_reporting($previousErrorReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        try {
            $lines = array_map(
                static fn(array $resource): string => sprintf(
                    '%s – %d',
                    self::normalizeName($resource['name']),
                    $resource['id'],
                ),
                $this->resources->listResources(),
            );

            $this->textResponse(200, $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL);
        } catch (Throwable $e) {
            Logger::error('B24 booking resource list failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->textResponse(502, 'Не удалось получить список ресурсов');
        } finally {
            error_reporting($previousErrorReporting);
        }
    }

    private static function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/[\x00-\x20\x7F]+/u', ' ', $name));
    }

    private function textResponse(int $status, string $body, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $body;
    }
}
