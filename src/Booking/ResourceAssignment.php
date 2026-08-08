<?php
declare(strict_types=1);

namespace App\Booking;

final readonly class ResourceAssignment
{
    public function __construct(
        public int $resourceId,
        public int $masterUserId,
        public string $serviceStationReference,
    ) {
    }
}
