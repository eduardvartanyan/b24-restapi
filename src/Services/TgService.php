<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;

class TgService
{
    public function handle(string $raw): array
    {
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            Logger::error('TG webhook: invalid JSON', ['raw' => $raw]);
            return ['status' => 400, 'body' => 'Invalid payload'];
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

        return ['status' => 200, 'body' => 'OK'];
    }
}
