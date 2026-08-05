<?php
declare(strict_types=1);

namespace App\Handlers;

use App\Helpers\Logger;
use InvalidArgumentException;

final readonly class BookingEventHandler
{
    private const SUPPORTED_EVENTS = [
        'ONBOOKINGADD',
        'ONBOOKINGUPDATE',
        'ONBOOKINGDELETE',
    ];

    /**
     * @return array{event: string, bookingId: int}
     */
    public function handle(array $payload): array
    {
        $event = strtoupper(trim((string) ($payload['event'] ?? '')));

        if (!in_array($event, self::SUPPORTED_EVENTS, true)) {
            throw new InvalidArgumentException('Unsupported booking event');
        }

        $bookingId = filter_var(
            $payload['data']['ID'] ?? $payload['data']['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($bookingId === false) {
            throw new InvalidArgumentException('Booking ID is required');
        }

        $context = [
            'event' => $event,
            'booking_id' => $bookingId,
            'event_handler_id' => $payload['event_handler_id'] ?? null,
            'timestamp' => $payload['ts'] ?? null,
            'member_id' => $payload['auth']['member_id'] ?? null,
            'domain' => $payload['auth']['domain'] ?? null,
        ];

        match ($event) {
            'ONBOOKINGADD' => $this->handleBookingAdded($context),
            'ONBOOKINGUPDATE' => $this->handleBookingUpdated($context),
            'ONBOOKINGDELETE' => $this->handleBookingDeleted($context),
        };

        return [
            'event' => $event,
            'bookingId' => $bookingId,
        ];
    }

    private function handleBookingAdded(array $context): void
    {
        Logger::info('B24 booking created', $context);
    }

    private function handleBookingUpdated(array $context): void
    {
        Logger::info('B24 booking updated', $context);
    }

    private function handleBookingDeleted(array $context): void
    {
        Logger::info('B24 booking deleted', $context);
    }
}
