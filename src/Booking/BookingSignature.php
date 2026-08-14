<?php
declare(strict_types=1);

namespace App\Booking;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final readonly class BookingSignature
{
    public function __construct(
        public int $resourceId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {
    }

    public static function fromBooking(Booking $booking): self
    {
        return new self($booking->resourceId, $booking->startsAt, $booking->endsAt);
    }

    public static function parse(?string $value): ?self
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $parts = explode('|', $value);
        $resourceId = filter_var($parts[0] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (count($parts) !== 3 || $resourceId === false) {
            throw new BookingDataException('Некорректная сигнатура онлайн-записи в сделке');
        }

        try {
            return new self(
                (int) $resourceId,
                new DateTimeImmutable($parts[1]),
                new DateTimeImmutable($parts[2]),
            );
        } catch (Throwable) {
            throw new BookingDataException('Некорректные даты в сигнатуре онлайн-записи');
        }
    }

    public function equals(self $other): bool
    {
        return (string) $this === (string) $other;
    }

    public function __toString(): string
    {
        return implode('|', [
            (string) $this->resourceId,
            $this->startsAt->format(DateTimeInterface::ATOM),
            $this->endsAt->format(DateTimeInterface::ATOM),
        ]);
    }
}
