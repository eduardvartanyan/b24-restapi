<?php
declare(strict_types=1);

use App\Booking\Booking;
use App\Booking\BookingAutomationConfig;
use App\Booking\BookingSignature;
use App\Booking\ControlTask;
use App\Booking\DealBookingState;
use App\Booking\MasterTask;
use App\Booking\MaxRecipient;
use App\Booking\NotificationRecipient;
use App\Booking\ResourceAssignment;
use App\Booking\ServiceStation;
use App\Contracts\BookingAutomationGateway;
use App\Handlers\BookingEventHandler;

require_once __DIR__ . '/../vendor/autoload.php';

final class FakeBookingAutomationGateway implements BookingAutomationGateway
{
    /** @var array<int, Booking> */
    public array $bookings = [];
    public DealBookingState $deal;
    public ResourceAssignment $assignment;
    public array $currentBookingUpdates = [];
    public array $workflowStarts = [];
    public array $deletedBookings = [];
    public array $reports = [];
    public array $signatureUpdates = [];
    public array $taskUpdates = [];
    public array $taskComments = [];
    public array $controlTaskUpdates = [];
    public array $controlTaskComments = [];
    public array $stationUpdates = [];
    public array $timelineComments = [];
    public array $cascadeMessages = [];
    public array $maxMessages = [];
    public array $masterReminders = [];
    public array $clientReminders = [];
    public bool $failWorkflow = false;
    public bool $failCascade = false;
    public MasterTask $task;
    public ControlTask $controlTask;
    public ServiceStation $station;

    public function __construct()
    {
        $this->deal = new DealBookingState(99, 10, null, null);
        $this->assignment = new ResourceAssignment(4, 588, 'CO_123');
        $this->task = new MasterTask(335626, 588, "СТОА: Кузя\nДата и время: 10.08.2030 11:00:00\nТС: BMW X5\nГ/н: А001АА38");
        $this->controlTask = new ControlTask(
            335627,
            "Проверить комментарий мастера.\nДата и время дефектовки: 10.08.2030 11:00:00",
            new DateTimeImmutable('2030-08-11 12:00:00+08:00'),
        );
        $this->station = new ServiceStation('CO_123', 'Кузя', 'Иркутск');
    }

    public function getBooking(int $bookingId): Booking
    {
        return $this->bookings[$bookingId] ?? throw new RuntimeException('Booking not found');
    }

    public function findBooking(int $bookingId): ?Booking
    {
        return $this->bookings[$bookingId] ?? null;
    }

    public function getDealIdForBooking(int $bookingId): int
    {
        return $this->deal->dealId;
    }

    public function getDealBookingState(int $dealId): DealBookingState
    {
        return $this->deal;
    }

    public function findResourceAssignment(int $resourceId): ResourceAssignment
    {
        return $this->assignment;
    }

    public function assertMasterCanReceiveTask(int $userId): void
    {
    }

    public function setCurrentBookingId(int $dealId, ?int $bookingId): void
    {
        $this->currentBookingUpdates[] = [$dealId, $bookingId];
        $this->deal = new DealBookingState(
            $this->deal->dealId,
            $this->deal->responsibleUserId,
            $bookingId,
            $this->deal->masterTaskId,
            $this->deal->contactId,
            $this->deal->bookingSignature,
            $this->deal->serviceStationReference,
            $this->deal->controlTaskId,
        );
    }

    public function setBookingSignature(int $dealId, ?string $signature): void
    {
        $this->signatureUpdates[] = [$dealId, $signature];
        $this->deal = new DealBookingState(
            $this->deal->dealId,
            $this->deal->responsibleUserId,
            $this->deal->currentBookingId,
            $this->deal->masterTaskId,
            $this->deal->contactId,
            $signature,
            $this->deal->serviceStationReference,
            $this->deal->controlTaskId,
        );
    }

    public function startMasterTaskWorkflow(int $dealId, int $userId, Booking $booking): void
    {
        if ($this->failWorkflow) {
            throw new RuntimeException('Workflow failed');
        }
        $this->workflowStarts[] = [$dealId, $userId, $booking->id];
    }

    public function getMasterTask(int $taskId): MasterTask
    {
        return $this->task;
    }

    public function getControlTask(int $taskId): ControlTask
    {
        return $this->controlTask;
    }

    public function getServiceStation(string $reference): ServiceStation
    {
        return $this->station;
    }

    public function getMasterRecipient(int $userId): MaxRecipient
    {
        return new MaxRecipient(100000 + $userId, 'Master');
    }

    public function getClientRecipient(int $contactId): ?NotificationRecipient
    {
        return new NotificationRecipient('+78880000' . $contactId);
    }

    public function updateMasterTask(
        MasterTask $task,
        int $responsibleUserId,
        Booking $booking,
        ServiceStation $serviceStation,
    ): void {
        $this->taskUpdates[] = [$task->id, $responsibleUserId, $booking->resourceId, $serviceStation->reference];
    }

    public function addMasterTaskComment(int $taskId, string $message): void
    {
        $this->taskComments[] = [$taskId, $message];
    }

    public function updateControlTask(
        ControlTask $task,
        Booking $booking,
        BookingSignature $previousBooking,
    ): void {
        $this->controlTaskUpdates[] = [
            $task->id,
            $booking->startsAt->format(DATE_ATOM),
            $booking->endsAt->getTimestamp() - $previousBooking->endsAt->getTimestamp(),
        ];
    }

    public function addControlTaskComment(int $taskId, string $message): void
    {
        $this->controlTaskComments[] = [$taskId, $message];
    }

    public function updateDealServiceStation(int $dealId, string $reference): void
    {
        $this->stationUpdates[] = [$dealId, $reference];
    }

    public function addDealTimelineComment(int $dealId, string $message): void
    {
        $this->timelineComments[] = [$dealId, $message];
    }

    public function sendCascadeMessage(int $contactId, NotificationRecipient $recipient, string $message): void
    {
        if ($this->failCascade) {
            throw new RuntimeException('Cascade failed');
        }
        $this->cascadeMessages[] = [$contactId, $recipient->phone, $message];
    }

    public function sendMaxMessage(MaxRecipient $recipient, string $message): void
    {
        if ($this->failCascade) {
            throw new RuntimeException('MAX failed');
        }
        $this->maxMessages[] = [$recipient->userId, $message];
    }

    public function rescheduleMasterReminder(
        int $dealId,
        MaxRecipient $recipient,
        int $taskId,
        Booking $booking,
        string $message,
    ): void {
        $this->masterReminders[] = [$dealId, $recipient->userId, $taskId, $booking->endsAt->format(DATE_ATOM)];
    }

    public function rescheduleClientReminder(
        int $dealId,
        ?NotificationRecipient $recipient,
        Booking $booking,
        string $message,
    ): void {
        $this->clientReminders[] = [$dealId, $recipient?->phone, $booking->startsAt->format(DATE_ATOM)];
    }

    public function deleteBooking(int $bookingId): void
    {
        $this->deletedBookings[] = $bookingId;
    }

    public function reportDealProblem(DealBookingState $deal, string $message): void
    {
        $this->reports[] = [$deal->dealId, $message];
    }
}

function booking(int $id, string $from = '2030-08-10 11:00:00'): Booking
{
    $startsAt = new DateTimeImmutable($from, new DateTimeZone('Asia/Irkutsk'));

    return new Booking($id, 4, $startsAt, $startsAt->modify('+1 hour'));
}

function payload(int $bookingId, string $event = 'ONBOOKINGADD'): array
{
    return [
        'event' => $event,
        'data' => ['ID' => (string) $bookingId],
        'auth' => ['domain' => 'example.bitrix24.ru'],
    ];
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual: ' . var_export($actual, true));
    }
}

$tests = [];

$tests['starts workflow and stores current booking'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[8] = booking(8);
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8));

    assertSameValue([[99, 8]], $gateway->currentBookingUpdates, 'Current booking was not stored');
    assertSameValue([[99, 588, 8]], $gateway->workflowStarts, 'Workflow was not started');
};

$tests['skips repeated event'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[8] = booking(8);
    $gateway->deal = new DealBookingState(99, 10, 8, 335626);
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8));

    assertSameValue([], $gateway->currentBookingUpdates, 'Repeated event changed the deal');
    assertSameValue([], $gateway->workflowStarts, 'Repeated event started another workflow');
};

$tests['starts workflow when only stale task id remains'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[10] = booking(10);
    $gateway->deal = new DealBookingState(99, 10, null, 335626);
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(10));

    assertSameValue([[99, 10]], $gateway->currentBookingUpdates, 'New booking was not stored');
    assertSameValue([[99, 588, 10]], $gateway->workflowStarts, 'Stale task ID blocked the workflow');
};

$tests['reports duplicate and keeps it when deletion is disabled'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[7] = booking(7, '2030-08-09 11:00:00');
    $gateway->bookings[8] = booking(8);
    $gateway->deal = new DealBookingState(99, 10, 7, 335626);
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8));

    assertSameValue([], $gateway->deletedBookings, 'Duplicate was deleted while deletion was disabled');
    assertSameValue(1, count($gateway->reports), 'Responsible user was not notified');
    assertSameValue([], $gateway->workflowStarts, 'Duplicate started a workflow');
};

$tests['deletes duplicate when deletion is enabled'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[7] = booking(7, '2030-08-09 11:00:00');
    $gateway->bookings[8] = booking(8);
    $gateway->deal = new DealBookingState(99, 10, 7, 335626);
    $config = new BookingAutomationConfig(deleteDuplicateBookings: true);
    $handler = new BookingEventHandler($gateway, $config);

    $handler->handle(payload(8));

    assertSameValue([8], $gateway->deletedBookings, 'Duplicate was not deleted');
    assertSameValue([], $gateway->workflowStarts, 'Deleted duplicate started a workflow');
};

$tests['rolls back current booking when workflow fails'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[8] = booking(8);
    $gateway->failWorkflow = true;
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    try {
        $handler->handle(payload(8));
        throw new RuntimeException('Expected workflow failure was not thrown');
    } catch (RuntimeException $e) {
        assertSameValue('Workflow failed', $e->getMessage(), 'Unexpected exception');
    }

    assertSameValue([[99, 8], [99, null]], $gateway->currentBookingUpdates, 'Current booking was not rolled back');
    assertSameValue(1, count($gateway->reports), 'Workflow failure was not reported');
};

$tests['updates existing task when booking period changes'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[8] = booking(8, '2030-08-10 13:00:00');
    $old = booking(8);
    $gateway->deal = new DealBookingState(99, 10, 8, 335626, 77, (string) $old->signature(), 'CO_123', 335627);
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8, 'ONBOOKINGUPDATE'));

    assertSameValue([[335626, 588, 4, 'CO_123']], $gateway->taskUpdates, 'Existing task was not updated');
    assertSameValue(1, count($gateway->controlTaskUpdates), 'Control task was not updated');
    assertSameValue(1, count($gateway->controlTaskComments), 'Control task change was not documented');
    assertSameValue(1, count($gateway->taskComments), 'Task change was not documented');
    assertSameValue(1, count($gateway->maxMessages), 'Master was not notified in MAX');
    assertSameValue(1, count($gateway->cascadeMessages), 'Client was not notified by cascade');
    assertSameValue(1, count($gateway->masterReminders), 'Master reminder was not rescheduled');
    assertSameValue(1, count($gateway->clientReminders), 'Client reminder was not rescheduled');
    assertSameValue([[99, (string) $gateway->bookings[8]->signature()]], $gateway->signatureUpdates, 'Signature was not stored');
};

$tests['does not notify client when only booking end changes'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $old = booking(8);
    $gateway->bookings[8] = new Booking(8, 4, $old->startsAt, $old->endsAt->modify('+30 minutes'));
    $gateway->deal = new DealBookingState(99, 10, 8, 335626, 77, (string) $old->signature(), 'CO_123', 335627);
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8, 'ONBOOKINGUPDATE'));

    assertSameValue(1, count($gateway->maxMessages), 'End-only change should notify master in MAX');
    assertSameValue([], $gateway->cascadeMessages, 'End-only change should not notify client');
    assertSameValue([], $gateway->clientReminders, 'Unchanged visit start should not reschedule client reminder');
    assertSameValue(1, count($gateway->masterReminders), 'Master reminder was not rescheduled');
    assertSameValue(1, count($gateway->controlTaskUpdates), 'End-only change should update control task deadline');
};

$tests['reports missing client recipient when visit start changes'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[8] = booking(8, '2030-08-10 13:00:00');
    $old = booking(8);
    $gateway->deal = new DealBookingState(99, 10, 8, 335626, null, (string) $old->signature(), 'CO_123', 335627);
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8, 'ONBOOKINGUPDATE'));

    assertSameValue([], $gateway->cascadeMessages, 'Client without recipient was unexpectedly notified');
    assertSameValue(1, count($gateway->reports), 'Missing client recipient was not reported');
};

$tests['reassigns existing task when resource changes'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $old = booking(8);
    $gateway->bookings[8] = new Booking(8, 6, $old->startsAt, $old->endsAt);
    $gateway->deal = new DealBookingState(99, 10, 8, 335626, 77, (string) $old->signature(), 'CO_123', 335627);
    $gateway->assignment = new ResourceAssignment(6, 600, 'CO_456');
    $gateway->station = new ServiceStation('CO_456', 'Фильтр', 'Иркутск');
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8, 'ONBOOKINGUPDATE'));

    assertSameValue([[335626, 600, 6, 'CO_456']], $gateway->taskUpdates, 'Task was not reassigned');
    assertSameValue([[99, 'CO_456']], $gateway->stationUpdates, 'Deal service station was not updated');
    assertSameValue(2, count($gateway->maxMessages), 'Old and new masters were not notified in MAX');
    assertSameValue(1, count($gateway->cascadeMessages), 'Client was not notified by cascade');
    assertSameValue(77, $gateway->cascadeMessages[0][0], 'Cascade workflow was not started for the contact');
};

$tests['rejects period update when control task id is missing'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $old = booking(8);
    $gateway->bookings[8] = booking(8, '2030-08-10 13:00:00');
    $gateway->deal = new DealBookingState(99, 10, 8, 335626, 77, (string) $old->signature(), 'CO_123');
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8, 'ONBOOKINGUPDATE'));

    assertSameValue([], $gateway->taskUpdates, 'Master task was partially updated without control task');
    assertSameValue([], $gateway->signatureUpdates, 'Rejected update was marked as processed');
    assertSameValue(1, count($gateway->reports), 'Missing control task ID was not reported');
    assertSameValue(
        true,
        str_contains($gateway->reports[0][1], 'ID задачи контроля дефектовки'),
        'Control task error details were not reported',
    );
};

$tests['skips duplicate booking update by signature'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[8] = booking(8);
    $gateway->deal = new DealBookingState(99, 10, 8, 335626, 77, (string) $gateway->bookings[8]->signature(), 'CO_123');
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8, 'ONBOOKINGUPDATE'));

    assertSameValue([], $gateway->taskUpdates, 'Duplicate update changed task');
    assertSameValue([], $gateway->cascadeMessages, 'Duplicate update sent notifications');
    assertSameValue([], $gateway->maxMessages, 'Duplicate update sent MAX notifications');
};

$tests['ignores update for non-current booking'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $gateway->bookings[8] = booking(8);
    $gateway->deal = new DealBookingState(99, 10, 7, 335626, 77, null, 'CO_123');
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8, 'ONBOOKINGUPDATE'));

    assertSameValue([], $gateway->taskUpdates, 'Non-current booking changed task');
    assertSameValue([], $gateway->reports, 'Non-current booking was reported as an error');
};

$tests['keeps update idempotent when cascade notification fails'] = static function (): void {
    $gateway = new FakeBookingAutomationGateway();
    $old = booking(8);
    $gateway->bookings[8] = booking(8, '2030-08-10 13:00:00');
    $gateway->deal = new DealBookingState(99, 10, 8, 335626, 77, (string) $old->signature(), 'CO_123', 335627);
    $gateway->failCascade = true;
    $handler = new BookingEventHandler($gateway, new BookingAutomationConfig());

    $handler->handle(payload(8, 'ONBOOKINGUPDATE'));
    $handler->handle(payload(8, 'ONBOOKINGUPDATE'));

    assertSameValue(1, count($gateway->taskUpdates), 'Repeated event updated task after notification failure');
    assertSameValue([[99, (string) $gateway->bookings[8]->signature()]], $gateway->signatureUpdates, 'New signature was not preserved');
    assertSameValue(1, count($gateway->reports), 'Notification failure was not reported to responsible user');
    assertSameValue(
        true,
        str_contains($gateway->reports[0][1], 'MAX failed'),
        'Notification failure details were not reported',
    );
};

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    $passed++;
    echo "[OK] {$name}" . PHP_EOL;
}

echo sprintf('%d tests passed', $passed) . PHP_EOL;
