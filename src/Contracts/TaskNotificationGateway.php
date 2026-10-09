<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Task\CreatedTask;
use App\Task\TaskNotificationRecipient;

interface TaskNotificationGateway
{
    public function getTask(int $taskId): CreatedTask;

    public function getNotificationRecipient(int $userId): TaskNotificationRecipient;
}
