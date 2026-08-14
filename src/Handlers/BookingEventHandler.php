<?php
declare(strict_types=1);

namespace App\Handlers;

use App\Booking\BookingAutomationConfig;
use App\Booking\BookingDataException;
use App\Booking\Booking;
use App\Booking\BookingSignature;
use App\Booking\DealBookingState;
use App\Booking\MasterTask;
use App\Booking\ServiceStation;
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
                    if ($deal->bookingSignature === null) {
                        $this->gateway->setBookingSignature($deal->dealId, (string) $booking->signature());
                    }
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
                    $this->gateway->setBookingSignature($deal->dealId, (string) $booking->signature());
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
        $deal = null;

        try {
            $booking = $this->gateway->getBooking($context['booking_id']);
            $dealId = $this->gateway->getDealIdForBooking($booking->id);

            $this->withDealLock($dealId, function () use ($booking, $context, $dealId, &$deal): void {
                $deal = $this->gateway->getDealBookingState($dealId);
                if ($deal->currentBookingId !== $booking->id) {
                    Logger::info('B24 booking update ignored for non-current booking', $context + [
                        'deal_id' => $deal->dealId,
                        'current_booking_id' => $deal->currentBookingId,
                    ]);
                    return;
                }

                if ($deal->masterTaskId === null) {
                    throw new BookingDataException(sprintf(
                        'У сделки %d не заполнен ID задачи мастера для актуальной онлайн-записи #%d',
                        $deal->dealId,
                        $booking->id,
                    ));
                }

                $newSignature = $booking->signature();
                $oldSignature = BookingSignature::parse($deal->bookingSignature);
                if ($oldSignature === null) {
                    throw new BookingDataException(sprintf(
                        'У сделки %d отсутствует исходная сигнатура онлайн-записи #%d',
                        $deal->dealId,
                        $booking->id,
                    ));
                }
                if ($newSignature->equals($oldSignature)) {
                    Logger::info('B24 booking update skipped as already processed', $context + [
                        'deal_id' => $deal->dealId,
                    ]);
                    return;
                }

                $task = $this->gateway->getMasterTask($deal->masterTaskId);
                $assignment = $this->gateway->findResourceAssignment($booking->resourceId);
                $this->gateway->assertMasterCanReceiveTask($assignment->masterUserId);
                $newMaster = $this->gateway->getMasterRecipient($assignment->masterUserId);
                $newStation = $this->gateway->getServiceStation($assignment->serviceStationReference);
                $oldMaster = $task->responsibleUserId === $assignment->masterUserId
                    ? $newMaster
                    : $this->gateway->getMasterRecipient($task->responsibleUserId);
                $clientContactId = $deal->contactId;
                $client = $clientContactId === null
                    ? null
                    : $this->gateway->getClientRecipient($clientContactId);

                $resourceChanged = $oldSignature->resourceId !== $newSignature->resourceId;
                $periodChanged = $oldSignature->startsAt != $newSignature->startsAt
                    || $oldSignature->endsAt != $newSignature->endsAt;
                $clientVisibleChange = $resourceChanged || $oldSignature->startsAt != $newSignature->startsAt;
                $controlTask = null;
                if ($periodChanged) {
                    if ($deal->controlTaskId === null) {
                        throw new BookingDataException(sprintf(
                            'У сделки %d не заполнен ID задачи контроля дефектовки',
                            $deal->dealId,
                        ));
                    }
                    $controlTask = $this->gateway->getControlTask($deal->controlTaskId);
                }

                $this->gateway->updateMasterTask(
                    $task,
                    $assignment->masterUserId,
                    $booking,
                    $newStation,
                );
                if ($controlTask !== null) {
                    $this->gateway->updateControlTask($controlTask, $booking, $oldSignature);
                }
                if ($resourceChanged || $deal->serviceStationReference !== $newStation->reference) {
                    $this->gateway->updateDealServiceStation($deal->dealId, $newStation->reference);
                }

                $changeSummary = $this->bookingChangeSummary(
                    $oldSignature,
                    $newSignature,
                    $resourceChanged,
                    $newStation,
                );
                $this->gateway->addMasterTaskComment($task->id, $changeSummary);
                if ($controlTask !== null) {
                    $this->gateway->addControlTaskComment($controlTask->id, $changeSummary);
                }
                $this->gateway->addDealTimelineComment($deal->dealId, $changeSummary);
                $this->gateway->setBookingSignature($deal->dealId, (string) $newSignature);

                $sideEffectErrors = [];
                if ($clientVisibleChange && $client === null) {
                    $sideEffectErrors[] = 'уведомление клиента: основной контакт или его телефон не найден';
                    Logger::error('B24 booking update client recipient not found', $context + [
                        'deal_id' => $deal->dealId,
                        'contact_id' => $deal->contactId,
                    ]);
                }
                if ($resourceChanged) {
                    $this->runUpdateSideEffect(
                        'уведомление прежнего мастера',
                        fn() => $this->gateway->sendMaxMessage(
                            $oldMaster,
                            $this->oldMasterCancellationMessage($task, $oldSignature),
                        ),
                        $sideEffectErrors,
                        $context,
                    );
                }
                $this->runUpdateSideEffect(
                    'уведомление актуального мастера',
                    fn() => $this->gateway->sendMaxMessage(
                        $newMaster,
                        $this->masterUpdateMessage($task, $booking, $newStation, $resourceChanged),
                    ),
                    $sideEffectErrors,
                    $context,
                );

                if ($client !== null && $clientContactId !== null && $clientVisibleChange) {
                    $this->runUpdateSideEffect(
                        'уведомление клиента',
                        fn() => $this->gateway->sendCascadeMessage(
                            $clientContactId,
                            $client,
                            $this->clientUpdateMessage($booking, $newStation),
                        ),
                        $sideEffectErrors,
                        $context,
                    );
                }

                $this->runUpdateSideEffect(
                    'перепланирование напоминания мастеру',
                    fn() => $this->gateway->rescheduleMasterReminder(
                        $deal->dealId,
                        $newMaster,
                        $task->id,
                        $booking,
                        $this->masterReminderMessage($task),
                    ),
                    $sideEffectErrors,
                    $context,
                );
                if ($clientVisibleChange) {
                    $this->runUpdateSideEffect(
                        'перепланирование напоминания клиенту',
                        fn() => $this->gateway->rescheduleClientReminder(
                            $deal->dealId,
                            $client,
                            $booking,
                            $this->clientReminderMessage($task, $booking, $newStation),
                        ),
                        $sideEffectErrors,
                        $context,
                    );
                }

                if ($sideEffectErrors !== []) {
                    $this->safeReportDealProblem(
                        $deal,
                        'Онлайн-запись синхронизирована, но не выполнены действия: ' . implode('; ', $sideEffectErrors) . '.',
                        $context,
                    );
                }

                Logger::info('B24 booking update synchronized', $context + [
                    'deal_id' => $deal->dealId,
                    'task_id' => $task->id,
                    'resource_changed' => $resourceChanged,
                    'period_changed' => $periodChanged,
                ]);
            });
        } catch (BookingDataException $e) {
            $this->safeReportDealProblem($deal, $e->getMessage(), $context);
            Logger::error('B24 booking update rejected', $context + ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->safeReportDealProblem(
                $deal,
                sprintf('Ошибка изменения онлайн-записи #%d: %s', $context['booking_id'], $e->getMessage()),
                $context,
            );
            throw $e;
        }
    }

    private function bookingChangeSummary(
        BookingSignature $old,
        BookingSignature $new,
        bool $resourceChanged,
        ServiceStation $station,
    ): string {
        $changes = [];
        if ($old->startsAt != $new->startsAt || $old->endsAt != $new->endsAt) {
            $changes[] = sprintf(
                'период: %s - %s -> %s - %s',
                $old->startsAt->format('d.m.Y H:i'),
                $old->endsAt->format('H:i'),
                $new->startsAt->format('d.m.Y H:i'),
                $new->endsAt->format('H:i'),
            );
        }
        if ($resourceChanged) {
            $changes[] = sprintf('ресурс: #%d -> #%d; СТОА: %s', $old->resourceId, $new->resourceId, $station->name);
        }

        return 'Онлайн-запись изменена: ' . implode('; ', $changes) . '.';
    }

    private function oldMasterCancellationMessage(MasterTask $task, BookingSignature $old): string
    {
        return sprintf(
            "Назначенная вам дефектовка автомобиля %s, %s отменена.\n\nДата и время: %s\nПричина: запись переназначена другому специалисту.",
            $this->taskValue($task, 'ТС:'),
            $this->taskValue($task, 'Г/н:'),
            $old->startsAt->format('d.m.Y H:i'),
        );
    }

    private function masterUpdateMessage(
        MasterTask $task,
        Booking $booking,
        ServiceStation $station,
        bool $resourceChanged,
    ): string {
        return sprintf(
            "%s дефектовка автомобиля %s, %s.\n\nДата и время: %s\nСТОА: %s\n\nЗадача: %s",
            $resourceChanged ? 'Вам назначена' : 'Изменена назначенная вам',
            $this->taskValue($task, 'ТС:'),
            $this->taskValue($task, 'Г/н:'),
            $booking->startsAt->format('d.m.Y H:i'),
            $station->name,
            sprintf($this->config->taskUrlTemplate, $task->id),
        );
    }

    private function clientUpdateMessage(Booking $booking, ServiceStation $station): string
    {
        return sprintf(
            "Ваша запись на дефектовку перенесена.\n\nНовая дата и время: %s\nСТОА: %s\nАдрес: %s",
            $booking->startsAt->format('d.m.Y H:i'),
            $station->name,
            $station->address,
        );
    }

    private function masterReminderMessage(MasterTask $task): string
    {
        return sprintf(
            "Напоминаем: укажите результат дефектовки автомобиля %s, %s.\n\nОставьте комментарий по установленному формату и завершите задачу:\n%s",
            $this->taskValue($task, 'ТС:'),
            $this->taskValue($task, 'Г/н:'),
            sprintf($this->config->taskUrlTemplate, $task->id),
        );
    }

    private function clientReminderMessage(
        MasterTask $task,
        Booking $booking,
        ServiceStation $station,
    ): string {
        return sprintf(
            "Напоминаем о записи на дефектовку автомобиля %s, %s.\n\nДата и время: %s\nСТОА: %s\nАдрес: %s",
            $this->taskValue($task, 'ТС:'),
            $this->taskValue($task, 'Г/н:'),
            $booking->startsAt->format('d.m.Y H:i'),
            $station->name,
            $station->address,
        );
    }

    private function taskValue(MasterTask $task, string $label): string
    {
        if (preg_match('/^' . preg_quote($label, '/') . '\s*(.+)$/mu', $task->description, $matches) === 1) {
            return trim($matches[1]);
        }

        return 'не указано';
    }

    private function runUpdateSideEffect(
        string $label,
        callable $callback,
        array &$errors,
        array $context,
    ): void {
        try {
            $callback();
        } catch (Throwable $e) {
            $details = trim($e->getMessage());
            $errors[] = $details === '' ? $label : sprintf('%s: %s', $label, $details);
            Logger::error('B24 booking update side effect failed', $context + [
                'action' => $label,
                'message' => $e->getMessage(),
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);
        }
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
