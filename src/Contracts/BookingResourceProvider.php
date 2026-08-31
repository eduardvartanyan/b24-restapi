<?php
declare(strict_types=1);

namespace App\Contracts;

interface BookingResourceProvider
{
    /** @return list<array{id: int, name: string}> */
    public function listResources(): array;
}
