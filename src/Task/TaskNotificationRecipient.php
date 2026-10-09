<?php
declare(strict_types=1);

namespace App\Task;

final readonly class TaskNotificationRecipient
{
    public function __construct(
        public int $bitrixUserId,
        public bool $extranet,
        public ?int $maxId,
        public string $name,
    ) {
    }
}
