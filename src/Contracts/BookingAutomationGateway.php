<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Booking\Booking;
use App\Booking\DealBookingState;
use App\Booking\ResourceAssignment;

interface BookingAutomationGateway
{
    public function getBooking(int $bookingId): Booking;

    public function findBooking(int $bookingId): ?Booking;

    public function getDealIdForBooking(int $bookingId): int;

    public function getDealBookingState(int $dealId): DealBookingState;

    public function findResourceAssignment(int $resourceId): ResourceAssignment;

    public function assertMasterCanReceiveTask(int $userId): void;

    public function setCurrentBookingId(int $dealId, ?int $bookingId): void;

    public function startMasterTaskWorkflow(int $dealId, int $userId, Booking $booking): void;

    public function deleteBooking(int $bookingId): void;

    public function reportDealProblem(DealBookingState $deal, string $message): void;
}
