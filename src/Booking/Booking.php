<?php
declare(strict_types=1);

namespace App\Booking;

use DateTimeImmutable;

final readonly class Booking
{
    public function __construct(
        public int $id,
        public int $resourceId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {
    }

    public function signature(): BookingSignature
    {
        return BookingSignature::fromBooking($this);
    }
}
