<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Handlers\TaskCreatedEventHandler;
use App\Helpers\Logger;
use InvalidArgumentException;
use JsonException;
use Throwable;

final readonly class TaskEventController
{
    public function __construct(private TaskCreatedEventHandler $handler)
    {
    }

    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->jsonResponse(405, ['error' => 'Method Not Allowed'], ['Allow' => 'POST']);
            return;
        }

        try {
            $result = $this->handler->handle($this->readPayload());
            $this->jsonResponse(200, ['success' => true] + $result);
        } catch (InvalidArgumentException|JsonException $e) {
            Logger::error('Invalid B24 task event', ['message' => $e->getMessage()]);
            $this->jsonResponse(422, ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::error('B24 task event failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->jsonResponse(500, ['error' => 'Internal Server Error']);
        }
    }

    private function readPayload(): array
    {
        if ($_POST !== []) {
            return $_POST;
        }

        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return [];
        }

        if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
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
