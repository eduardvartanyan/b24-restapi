<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;

readonly class MaxService
{
    public function __construct(private B24Service $b24Service) {}

    public function handle(string $raw): array
    {
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            Logger::error('Max webhook: invalid JSON', ['raw' => $raw]);
            return ['status' => 400, 'body' => 'Invalid payload'];
        }

        if (isset($payload['update_type'])) {
            switch ($payload['update_type']) {
                case 'bot_started':
                    $this->sendMessage($payload['chat_id'], [
                        'text' => 'Приветственное сообщение',
                    ]);
                    $contactId = $this->b24Service->getContactIdByRid($payload['payload']);
                    Logger::info('Max webhook: incoming update', [
                        'update_type' => $payload['update_type'],
                        'contactId'   => $contactId,
                    ]);
                    break;
                case 'message_created':
                    Logger::info('Max webhook: incoming update', [
                        'update_type' => $payload['update_type'],
                        'payload'   => $payload,
                    ]);
//                    $this->sendMessage($payload['message']['recipient']['chat_id'], [
//                        'text' => 'Автоответ',
//                    ]);
                    break;
            }
        }

        return ['status' => 200, 'body' => 'OK'];
    }

    private function sendMessage(int $chatId, array $payload): void
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://platform-api.max.ru/messages?' . http_build_query(['chat_id' => $chatId]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $_ENV['MAX_BOT_TOKEN'],
                'Content-Type: application/json',
            ],
        ]);

        curl_exec($curl);
        curl_close($curl);
    }
}
