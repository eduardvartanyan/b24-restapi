<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Handlers\BookingEventHandler;
use App\Helpers\Logger;
use InvalidArgumentException;
use JsonException;
use Throwable;

final readonly class BookingEventController
{
    public function __construct(private BookingEventHandler $handler)
    {
    }

    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->jsonResponse(405, ['error' => 'Method Not Allowed'], ['Allow' => 'POST']);
            return;
        }

        try {
            $payload = $this->readPayload();
            $result = $this->handler->handle($payload);

            $this->jsonResponse(200, [
                'success' => true,
                'event' => $result['event'],
                'bookingId' => $result['bookingId'],
            ]);
        } catch (InvalidArgumentException|JsonException $e) {
            Logger::error('Invalid B24 booking event', ['message' => $e->getMessage()]);
            $this->jsonResponse(422, ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::error('B24 booking event failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->jsonResponse(500, ['error' => 'Internal Server Error']);
        }
    }

    /**
     * @throws JsonException
     */
    private function readPayload(): array
    {
        if ($_POST !== []) {
            return $_POST;
        }

        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return [];
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : [];
        }

        parse_str($raw, $payload);

        return is_array($payload) ? $payload : [];
    }

    private function jsonResponse(int $status, array $body, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
