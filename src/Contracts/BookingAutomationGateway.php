<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Booking\Booking;
use App\Booking\BookingSignature;
use App\Booking\ControlTask;
use App\Booking\DealBookingState;
use App\Booking\MasterTask;
use App\Booking\MaxRecipient;
use App\Booking\NotificationRecipient;
use App\Booking\ResourceAssignment;
use App\Booking\ServiceStation;

interface BookingAutomationGateway
{
    public function getBooking(int $bookingId): Booking;

    public function findBooking(int $bookingId): ?Booking;

    public function getDealIdForBooking(int $bookingId): int;

    public function getDealBookingState(int $dealId): DealBookingState;

    public function findResourceAssignment(int $resourceId): ResourceAssignment;

    public function assertMasterCanReceiveTask(int $userId): void;

    public function setCurrentBookingId(int $dealId, ?int $bookingId): void;

    public function setBookingSignature(int $dealId, ?string $signature): void;

    public function startMasterTaskWorkflow(int $dealId, int $userId, Booking $booking): void;

    public function getMasterTask(int $taskId): MasterTask;

    public function getControlTask(int $taskId): ControlTask;

    public function getServiceStation(string $reference): ServiceStation;

    public function getMasterRecipient(int $userId): MaxRecipient;

    public function getClientRecipient(int $contactId): ?NotificationRecipient;

    public function updateMasterTask(
        MasterTask $task,
        int $responsibleUserId,
        Booking $booking,
        ServiceStation $serviceStation,
    ): void;

    public function addMasterTaskComment(int $taskId, string $message): void;

    public function updateControlTask(
        ControlTask $task,
        Booking $booking,
        BookingSignature $previousBooking,
    ): void;

    public function addControlTaskComment(int $taskId, string $message): void;

    public function updateDealServiceStation(int $dealId, string $reference): void;

    public function addDealTimelineComment(int $dealId, string $message): void;

    public function sendCascadeMessage(int $contactId, NotificationRecipient $recipient, string $message): void;

    public function sendMaxMessage(MaxRecipient $recipient, string $message): void;

    public function rescheduleMasterReminder(
        int $dealId,
        MaxRecipient $recipient,
        int $taskId,
        Booking $booking,
        string $message,
    ): void;

    public function rescheduleClientReminder(
        int $dealId,
        ?NotificationRecipient $recipient,
        Booking $booking,
        string $message,
    ): void;

    public function deleteBooking(int $bookingId): void;

    public function reportDealProblem(DealBookingState $deal, string $message): void;
}
