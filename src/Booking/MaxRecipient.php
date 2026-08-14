<?php
declare(strict_types=1);

namespace App\Booking;

final readonly class MaxRecipient
{
    public function __construct(
        public int $userId,
        public string $name = '',
    ) {
    }
}
