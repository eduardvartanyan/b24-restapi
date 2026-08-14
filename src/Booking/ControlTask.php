<?php
declare(strict_types=1);

namespace App\Booking;

use DateTimeImmutable;

final readonly class ControlTask
{
    public function __construct(
        public int $id,
        public string $description,
        public ?DateTimeImmutable $deadline,
    ) {
    }
}
