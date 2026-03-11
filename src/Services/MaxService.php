<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use App\Repositories\ChatStateRepository;
use App\Support\MessageCatalog;
use Bot;
use PHPMaxBot;
use PHPMaxBot\Exceptions\ApiException;
use PHPMaxBot\Exceptions\MaxBotException;
use PHPMaxBot\Helpers\Keyboard;
use Throwable;

readonly class MaxService
{
    private MessageCatalog $messages;

    public function __construct(
        private B24Service $b24Service,
        private PHPMaxBot $maxBot,
        private ChatStateRepository $chatStateRepository,
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
            $this->registerActions();

            $this->maxBot->command('menu', function() {
                $update = PHPMaxBot::$currentUpdate;
                $contactId = $this->b24Service->getContactIdByMaxChatId($update['message']['recipient']['chat_id']);
                $menu = $this->getMenu($contactId);

                return Bot::sendMessage($this->messages->get('message__menu'), ['attachments' => [$menu]]);
            });

            $this->maxBot->start([
                'message_created',
                'message_callback',
                'bot_started'
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
        } catch (Throwable $e) {
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

            $menu = $this->getMenu($contactId);

            if (isset($contactId)) {
                $this->b24Service->setMaxChatId($contactId, $update['chat_id']);
            }

            return Bot::sendMessage($this->messages->get('message__welcome'), ['attachments' => [$menu]]);
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

    private function registerActions(): void
    {
        $this->maxBot->action('dtp', function() {
            $update = PHPMaxBot::$currentUpdate;

            return Bot::answerOnCallback($update['callback']['callback_id'], [
                'message' => [
                    'text' => $this->messages->get('message__dtp_location'),
                    'attachments' => [Keyboard::inlineKeyboard([
                        [Keyboard::requestGeoLocation($this->messages->get('button_label__geo'))],
                        [Keyboard::callback($this->messages->get('button_label__manual_address'), 'manual_address')],
                        [Keyboard::callback($this->messages->get('button_label__back'), 'menu')],
                    ])]
                ]
            ]);
        });

        $this->maxBot->action('manual_address', function() {
            $update = PHPMaxBot::$currentUpdate;
            $chatId = $update['message']['recipient']['chat_id'];
            $userId = $update['message']['recipient']['user_id'];

            $this->chatStateRepository->saveStateForMinutes(
                $chatId,
                'dtp.waiting_address',
                30,
                $userId,
                ['scenario' => 'dtp',]
            );

            return Bot::answerOnCallback($update['callback']['callback_id'], [
                'message' => [
                    'text' => $this->messages->get('message__dtp_manual_address'),
                    'attachments' => [Keyboard::inlineKeyboard([
                        [Keyboard::callback($this->messages->get('button_label__back'), 'dtp')],
                    ])]
                ]
            ]);
        });

        $this->maxBot->action('menu', function() {
            $update = PHPMaxBot::$currentUpdate;
            $contactId = $this->b24Service->getContactIdByMaxChatId($update['message']['recipient']['chat_id']);

            return Bot::answerOnCallback($update['callback']['callback_id'], [
                'message' => [
                    'text' => $this->messages->get('message__menu'),
                    'attachments' => [$this->getMenu($contactId)]
                ]
            ]);
        });
    }

    private function getMenu(?int $contactId): array
    {
        if (isset($contactId)) {
            $keyboard = Keyboard::inlineKeyboard([
                [Keyboard::callback($this->messages->get('button_label__dtp'), 'dtp')],
                [Keyboard::callback($this->messages->get('button_label__status'), 'status')],
                [Keyboard::callback($this->messages->get('button_label__ask'), 'ask')],
            ]);
        } else {
            $keyboard = Keyboard::inlineKeyboard([
                [Keyboard::callback($this->messages->get('button_label__dtp'), 'dtp')],
                [Keyboard::callback($this->messages->get('button_label__ask'), 'ask')],
            ]);
        }
        return $keyboard;
    }
}


//Bot::deleteMyCommands();
//Bot::setMyCommands([
//    ['name' => 'menu', 'description' => 'Открыть команды'],
//]);