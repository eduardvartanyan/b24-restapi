<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use App\Support\MessageCatalog;
use Bot;
use PHPMaxBot;
use PHPMaxBot\Exceptions\ApiException;
use PHPMaxBot\Exceptions\MaxBotException;

readonly class MaxService
{
    private MessageCatalog $messages;

    public function __construct(
        private B24Service $b24Service,
        private PHPMaxBot $maxBot
    ) {
        $this->messages = new MessageCatalog(__DIR__ . '/../Support/Messages/chatbot.php');
    }

    public function handle(string $raw): array
    {
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            Logger::error('Max webhook: invalid JSON', ['raw' => $raw]);
            return ['status' => 400, 'body' => 'Invalid payload'];
        }

        Logger::info('Max webhook: incoming update', [
            'update_type' => $payload['update_type'] ?? null,
            'payload'     => $payload,
        ]);

        try {
            $this->registerHandlers();

            $this->maxBot->start([
                'bot_started',
                'message_created',
            ]);

            return ['status' => 200, 'body' => 'OK'];
        } catch (ApiException $e) {
            Logger::error('Max webhook: API exception', [
                'message' => $e->getMessage(),
                'code'    => method_exists($e, 'getApiErrorCode') ? $e->getApiErrorCode() : null,
            ]);

            return ['status' => 500, 'body' => 'MAX API error'];
        } catch (MaxBotException $e) {
            Logger::error('Max webhook: library exception', [
                'message' => $e->getMessage(),
                'context' => method_exists($e, 'getContext') ? $e->getContext() : null,
            ]);

            return ['status' => 500, 'body' => 'Bot error'];
        } catch (\Throwable $e) {
            Logger::error('Max webhook: unexpected exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return ['status' => 500, 'body' => 'Internal error'];
        }
    }

    private function registerHandlers(): void
    {
        $this->maxBot->on('bot_started', function () {
            $update = PHPMaxBot::$currentUpdate;

            $rid = $update['payload'] ?? null;
            $contactId = $rid ? $this->b24Service->getContactIdByRid((string)$rid) : null;

            Logger::info('Max webhook: bot_started', [
                'chat_id'    => $update['chat_id'] ?? null,
                'payload'    => $rid,
                'contact_id' => $contactId,
            ]);

            return Bot::sendMessage($this->messages->get('welcome'));
        });

        $this->maxBot->on('message_created', function () {
            $update = PHPMaxBot::$currentUpdate;
            $text   = Bot::getText();
            $chatId = $update['message']['recipient']['chat_id'];

            Logger::info('Max webhook: message_created', [
                'chat_id' => $chatId,
                'text'    => $text,
                'payload' => $update,
            ]);

            Bot::sendAction($chatId, 'typing_on');
            sleep(1);

             return Bot::sendMessage('Автоответ');
        });
    }
}


//Bot::deleteMyCommands();
//Bot::setMyCommands([
//    ['name' => 'menu', 'description' => 'Открыть команды'],
//]);