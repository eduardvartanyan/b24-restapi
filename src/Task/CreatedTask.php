<?php
declare(strict_types=1);

namespace App\Task;

use DateTimeImmutable;

final readonly class CreatedTask
{
    public function __construct(
        public int $id,
        public string $title,
        public int $responsibleUserId,
        public string $creatorName,
        public ?DateTimeImmutable $deadline,
    ) {
    }
}
