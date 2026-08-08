<?php
declare(strict_types=1);

namespace App\Handlers;

use App\Booking\BookingAutomationConfig;
use App\Booking\BookingDataException;
use App\Booking\DealBookingState;
use App\Contracts\BookingAutomationGateway;
use App\Helpers\Logger;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class BookingEventHandler
{
    private const SUPPORTED_EVENTS = [
        'ONBOOKINGADD',
        'ONBOOKINGUPDATE',
        'ONBOOKINGDELETE',
    ];

    public function __construct(
        private BookingAutomationGateway $gateway,
        private BookingAutomationConfig $config,
    ) {
    }

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
        $deal = null;

        try {
            $booking = $this->gateway->getBooking($context['booking_id']);
            $dealId = $this->gateway->getDealIdForBooking($booking->id);

            $this->withDealLock($dealId, function () use ($booking, $context, $dealId, &$deal): void {
                $deal = $this->gateway->getDealBookingState($dealId);

                if ($deal->currentBookingId === $booking->id) {
                    Logger::info('B24 booking add event skipped as already processed', $context + [
                        'deal_id' => $deal->dealId,
                    ]);
                    return;
                }

                if ($deal->currentBookingId !== null) {
                    $previousBooking = $this->gateway->findBooking($deal->currentBookingId);
                    if ($previousBooking !== null && $previousBooking->startsAt > new DateTimeImmutable()) {
                        $message = sprintf(
                            'Онлайн-запись #%d не обработана: в сделке уже есть будущая онлайн-запись #%d.',
                            $booking->id,
                            $previousBooking->id,
                        );

                        if ($this->config->deleteDuplicateBookings) {
                            $this->gateway->deleteBooking($booking->id);
                            $message .= ' Новая запись удалена как дубль.';
                        } else {
                            $message .= ' Автоудаление дубля отключено до проверки клиентских уведомлений.';
                        }

                        $this->safeReportDealProblem($deal, $message, $context);
                        Logger::info('B24 duplicate booking rejected', $context + [
                            'deal_id' => $deal->dealId,
                            'previous_booking_id' => $previousBooking->id,
                            'deleted' => $this->config->deleteDuplicateBookings,
                        ]);
                        return;
                    }
                }

                $assignment = $this->gateway->findResourceAssignment($booking->resourceId);
                $this->gateway->assertMasterCanReceiveTask($assignment->masterUserId);

                $previousBookingId = $deal->currentBookingId;
                $this->gateway->setCurrentBookingId($deal->dealId, $booking->id);

                try {
                    $this->gateway->startMasterTaskWorkflow(
                        $deal->dealId,
                        $assignment->masterUserId,
                        $booking,
                    );
                } catch (Throwable $e) {
                    $this->gateway->setCurrentBookingId($deal->dealId, $previousBookingId);
                    throw $e;
                }

                Logger::info('B24 booking created and workflow started', $context + [
                    'deal_id' => $deal->dealId,
                    'resource_id' => $booking->resourceId,
                    'master_user_id' => $assignment->masterUserId,
                    'workflow_template_id' => $this->config->workflowTemplateId,
                ]);
            });
        } catch (BookingDataException $e) {
            $this->safeReportDealProblem($deal, $e->getMessage(), $context);
            Logger::error('B24 booking add rejected', $context + ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->safeReportDealProblem(
                $deal,
                sprintf('Ошибка обработки онлайн-записи #%d: %s', $context['booking_id'], $e->getMessage()),
                $context,
            );
            throw $e;
        }
    }

    private function handleBookingUpdated(array $context): void
    {
        Logger::info('B24 booking updated', $context);
    }

    private function handleBookingDeleted(array $context): void
    {
        Logger::info('B24 booking deleted', $context);
    }

    private function withDealLock(int $dealId, callable $callback): void
    {
        $lock = fopen(sys_get_temp_dir() . '/forsite-booking-deal-' . $dealId . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException(sprintf('Не удалось создать блокировку для сделки %d', $dealId));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(sprintf('Не удалось заблокировать сделку %d', $dealId));
            }
            $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function safeReportDealProblem(?DealBookingState $deal, string $message, array $context): void
    {
        if ($deal === null) {
            return;
        }

        try {
            $this->gateway->reportDealProblem($deal, $message);
        } catch (Throwable $e) {
            Logger::error('Failed to report B24 booking problem to deal', $context + [
                'deal_id' => $deal->dealId,
                'message' => $e->getMessage(),
            ]);
        }
    }

}
