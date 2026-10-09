<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Booking\MaxRecipient;
use App\Task\CreatedTask;

interface TaskNotificationGateway
{
    public function getTask(int $taskId): CreatedTask;

    public function getMaxRecipient(int $userId): ?MaxRecipient;
}
