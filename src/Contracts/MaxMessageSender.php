<?php
declare(strict_types=1);

namespace App\Contracts;

interface MaxMessageSender
{
    public function sendToUser(int $userId, string $message): void;
}
