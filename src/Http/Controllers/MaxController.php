<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\Logger;
use App\Services\MaxService;

readonly class MaxController
{
    public function __construct(private MaxService $maxService) {}

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $result = $this->maxService->handle($raw);

        http_response_code($result['status']);
        echo $result['body'];
    }

    public function handleWebhook(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        if (!isset($_REQUEST['m'])) {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        switch ($_REQUEST['m']) {
            case 'mark_in_work':
                $result = $this->maxService->markDtpRequestInWork(
                    dealId: $_REQUEST['d'],
                    commissarName: $_REQUEST['c'],
                    commissarPhone: $_REQUEST['p']
                );
                http_response_code($result['status']);
                echo $result['body'];
                break;
        }
    }

    public function handleB24MessageWebhook(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $payload = $_REQUEST;

        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $payload = array_merge($payload, $json);
            }
        }

        $chatId = $payload['chatId'] ?? $payload['chat_id'] ?? null;
        $message = $payload['message'] ?? $payload['text'] ?? null;

        if ($chatId === null || trim((string)$chatId) === '') {
            $this->jsonResponse(400, ['error' => 'chatId is required']);
            return;
        }

        if ($message === null || trim((string)$message) === '') {
            $this->jsonResponse(400, ['error' => 'message is required']);
            return;
        }

        Logger::info('B24 Max message webhook: incoming request', [
            'chat_id' => $chatId,
        ]);

        $result = $this->maxService->sendMessageToChat($chatId, (string)$message);
        $this->jsonResponse($result['status'], ['message' => $result['body']]);
    }

    private function jsonResponse(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
