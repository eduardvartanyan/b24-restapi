<?php
declare(strict_types=1);

use App\Booking\Booking;
use App\Booking\BookingAutomationConfig;
use App\Booking\DealBookingState;
use App\Booking\ResourceAssignment;
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
    public bool $failWorkflow = false;

    public function __construct()
    {
        $this->deal = new DealBookingState(99, 10, null, null);
        $this->assignment = new ResourceAssignment(4, 588, 'CO_123');
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
        );
    }

    public function startMasterTaskWorkflow(int $dealId, int $userId, Booking $booking): void
    {
        if ($this->failWorkflow) {
            throw new RuntimeException('Workflow failed');
        }
        $this->workflowStarts[] = [$dealId, $userId, $booking->id];
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

function payload(int $bookingId): array
{
    return [
        'event' => 'ONBOOKINGADD',
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

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    $passed++;
    echo "[OK] {$name}" . PHP_EOL;
}

echo sprintf('%d tests passed', $passed) . PHP_EOL;
