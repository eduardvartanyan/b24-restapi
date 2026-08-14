<?php
declare(strict_types=1);

namespace App\Booking;

final readonly class ServiceStation
{
    public function __construct(
        public string $reference,
        public string $name,
        public string $address,
    ) {
    }

    public function displayName(): string
    {
        return implode(', ', array_filter([$this->name, $this->address]));
    }
}
