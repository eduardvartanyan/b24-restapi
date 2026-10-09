<?php
declare(strict_types=1);

namespace App\Contracts;

interface TaskNotificationStore
{
    public function executeOnce(int $taskId, callable $operation): bool;
}
