<?php
declare(strict_types=1);

namespace App\Booking;

final readonly class NotificationRecipient
{
    public function __construct(
        public string $phone,
        public string $name = '',
    ) {
    }
}
