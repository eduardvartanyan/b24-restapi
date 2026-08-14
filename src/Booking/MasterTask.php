<?php
declare(strict_types=1);

namespace App\Booking;

final readonly class MasterTask
{
    public function __construct(
        public int $id,
        public int $responsibleUserId,
        public string $description,
    ) {
    }
}
