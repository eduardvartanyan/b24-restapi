<?php
declare(strict_types=1);

namespace App\Booking;

final readonly class DealBookingState
{
    public function __construct(
        public int $dealId,
        public int $responsibleUserId,
        public ?int $currentBookingId,
        public ?int $masterTaskId,
    ) {
    }
}
