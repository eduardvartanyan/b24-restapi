<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\MaxMessageSender;
use App\Helpers\Logger;
use Bot;
use InvalidArgumentException;
use PHPMaxBot;

final readonly class MaxBotMessageSender implements MaxMessageSender
{
    public function __construct(private string $token)
    {
        if (trim($token) === '') {
            throw new InvalidArgumentException('MAX notice bot token is required');
        }
    }

    public function sendToUser(int $userId, string $message): void
    {
        $message = trim($message);
        if ($userId <= 0 || $message === '') {
            throw new InvalidArgumentException('MAX user ID and message are required');
        }

        // The library stores the active token statically, so initialize it immediately before sending.
        new PHPMaxBot($this->token);
        Bot::sendMessageToUser($userId, $message);

        Logger::info('Max notice bot: message sent to user', ['user_id' => $userId]);
    }
}
