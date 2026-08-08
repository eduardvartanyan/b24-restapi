<?php
declare(strict_types=1);

namespace App\Services;

use App\Booking\Booking;
use App\Booking\BookingAutomationConfig;
use App\Booking\BookingDataException;
use App\Booking\DealBookingState;
use App\Booking\ResourceAssignment;
use App\Contracts\BookingAutomationGateway;
use Bitrix24\SDK\Core\Exceptions\BaseException;
use Bitrix24\SDK\Core\Exceptions\ItemNotFoundException;
use Bitrix24\SDK\Services\ServiceBuilder;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Bitrix24BookingAutomationGateway implements BookingAutomationGateway
{
    /** @var array{listId: int, resource: string, stoa: string, user: string, active: ?string}|null */
    private ?array $resourceListMetadata = null;

    public function __construct(
        private readonly ServiceBuilder $b24,
        private readonly BookingAutomationConfig $config,
    ) {
    }

    public function getBooking(int $bookingId): Booking
    {
        $result = $this->call('booking.v1.booking.get', ['id' => $bookingId]);
        $booking = $this->array($result['booking'] ?? null, 'Бронирование не найдено в ответе REST');
        $id = $this->positiveInt($booking['id'] ?? null, 'Некорректный ID бронирования');
        $resourceIds = array_values(array_filter(
            array_map(static fn(mixed $value): int => (int) $value, (array) ($booking['resourceIds'] ?? [])),
            static fn(int $value): bool => $value > 0,
        ));

        if (count($resourceIds) !== 1) {
            throw new BookingDataException(sprintf(
                'Для бронирования %d ожидался один ресурс, получено: %d',
                $id,
                count($resourceIds),
            ));
        }

        $datePeriod = $this->array($booking['datePeriod'] ?? null, 'В бронировании отсутствует период');
        $startsAt = $this->dateTime($datePeriod['from'] ?? null, 'начала');
        $endsAt = $this->dateTime($datePeriod['to'] ?? null, 'окончания');
        if ($endsAt <= $startsAt) {
            throw new BookingDataException(sprintf('Некорректный период бронирования %d', $id));
        }

        return new Booking($id, $resourceIds[0], $startsAt, $endsAt);
    }

    public function findBooking(int $bookingId): ?Booking
    {
        try {
            return $this->getBooking($bookingId);
        } catch (BaseException $e) {
            $message = strtolower($e->getMessage());
            if ($e instanceof ItemNotFoundException
                || str_contains($message, '1021')
                || str_contains($message, 'booking not found')) {
                return null;
            }

            throw $e;
        }
    }

    public function getDealIdForBooking(int $bookingId): int
    {
        $result = $this->call('booking.v1.booking.externalData.list', ['bookingId' => $bookingId]);
        $externalData = $result['externalData'] ?? [];
        if (!is_array($externalData)) {
            throw new BookingDataException('Некорректный ответ со связями бронирования');
        }

        $dealIds = [];
        foreach ($externalData as $link) {
            if (!is_array($link)) {
                continue;
            }

            $moduleId = strtolower(trim((string) ($link['moduleId'] ?? '')));
            $entityTypeId = strtoupper(trim((string) ($link['entityTypeId'] ?? '')));
            if ($moduleId !== 'crm' || $entityTypeId !== 'DEAL') {
                continue;
            }

            $value = preg_replace('/^DEAL_/i', '', trim((string) ($link['value'] ?? '')));
            $dealId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($dealId !== false) {
                $dealIds[] = (int) $dealId;
            }
        }

        $dealIds = array_values(array_unique($dealIds));
        if (count($dealIds) !== 1) {
            throw new BookingDataException(sprintf(
                'Для бронирования %d ожидалась одна связь с CRM-сделкой, получено: %d',
                $bookingId,
                count($dealIds),
            ));
        }

        return $dealIds[0];
    }

    public function getDealBookingState(int $dealId): DealBookingState
    {
        $deal = $this->call('crm.deal.get', ['id' => $dealId]);

        return new DealBookingState(
            dealId: $this->positiveInt($deal['ID'] ?? null, 'CRM-сделка не найдена'),
            responsibleUserId: $this->positiveInt(
                $deal['ASSIGNED_BY_ID'] ?? null,
                'В CRM-сделке не указан ответственный',
            ),
            currentBookingId: $this->nullablePositiveInt($deal[$this->config->currentBookingField] ?? null),
            masterTaskId: $this->nullablePositiveInt($deal[$this->config->masterTaskField] ?? null),
        );
    }

    public function findResourceAssignment(int $resourceId): ResourceAssignment
    {
        $metadata = $this->getResourceListMetadata();
        $select = ['ID', 'NAME', $metadata['resource'], $metadata['stoa'], $metadata['user']];
        if ($metadata['active'] !== null) {
            $select[] = $metadata['active'];
        }

        $items = $this->call('lists.element.get', [
            'IBLOCK_TYPE_ID' => 'lists',
            'IBLOCK_ID' => $metadata['listId'],
            'SELECT' => $select,
            'FILTER' => [$metadata['resource'] => (string) $resourceId],
        ]);

        $matches = [];
        foreach ($items as $item) {
            if (!is_array($item) || !$this->isActiveListItem($item, $metadata['active'])) {
                continue;
            }

            $itemResourceId = $this->positiveInt(
                $this->singlePropertyValue($item, $metadata['resource']),
                'В элементе списка указан некорректный ID ресурса',
            );
            if ($itemResourceId !== $resourceId) {
                continue;
            }

            $matches[] = new ResourceAssignment(
                resourceId: $itemResourceId,
                masterUserId: $this->positiveInt(
                    $this->singlePropertyValue($item, $metadata['user']),
                    'В элементе списка указан некорректный ID экстранет-пользователя',
                ),
                serviceStationReference: $this->requiredString(
                    $this->singlePropertyValue($item, $metadata['stoa']),
                    'В элементе списка не указана СТОА',
                ),
            );
        }

        if (count($matches) !== 1) {
            throw new BookingDataException(sprintf(
                'Для ресурса %d ожидалась одна активная связь, получено: %d',
                $resourceId,
                count($matches),
            ));
        }

        return $matches[0];
    }

    public function assertMasterCanReceiveTask(int $userId): void
    {
        $users = $this->call('user.get', [
            'FILTER' => ['ID' => $userId],
            'ADMIN_MODE' => true,
        ]);

        $user = null;
        foreach ($users as $candidate) {
            if (is_array($candidate) && (int) ($candidate['ID'] ?? 0) === $userId) {
                $user = $candidate;
                break;
            }
        }

        if ($user === null) {
            throw new BookingDataException(sprintf('Экстранет-пользователь %d не найден', $userId));
        }

        if (!in_array($user['ACTIVE'] ?? null, [true, 1, '1', 'Y'], true)) {
            throw new BookingDataException(sprintf('Экстранет-пользователь %d неактивен', $userId));
        }

        $userType = trim((string) ($user['USER_TYPE'] ?? ''));
        if ($userType !== '' && $userType !== 'extranet') {
            throw new BookingDataException(sprintf('Пользователь %d не является экстранет-пользователем', $userId));
        }

        if (trim((string) ($user['PERSONAL_MOBILE'] ?? '')) === '') {
            throw new BookingDataException(sprintf('У экстранет-пользователя %d не указан мобильный телефон', $userId));
        }
    }

    public function setCurrentBookingId(int $dealId, ?int $bookingId): void
    {
        $this->call('crm.deal.update', [
            'id' => $dealId,
            'fields' => [$this->config->currentBookingField => $bookingId === null ? '' : (string) $bookingId],
        ]);
    }

    public function startMasterTaskWorkflow(int $dealId, int $userId, Booking $booking): void
    {
        $this->call('bizproc.workflow.start', [
            'TEMPLATE_ID' => $this->config->workflowTemplateId,
            'DOCUMENT_ID' => ['crm', 'CCrmDocumentDeal', 'DEAL_' . $dealId],
            'PARAMETERS' => [
                'user_id' => 'user_' . $userId,
                'date_from' => $booking->startsAt->format(DateTimeInterface::ATOM),
                'date_to' => $booking->endsAt->format(DateTimeInterface::ATOM),
            ],
        ]);
    }

    public function deleteBooking(int $bookingId): void
    {
        $this->call('booking.v1.booking.delete', ['id' => $bookingId]);
    }

    public function reportDealProblem(DealBookingState $deal, string $message): void
    {
        $this->call('crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => $deal->dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => $message,
            ],
        ]);

        $this->call('im.notify.system.add', [
            'USER_ID' => $deal->responsibleUserId,
            'MESSAGE' => $message,
        ]);
    }

    /** @return array{listId: int, resource: string, stoa: string, user: string, active: ?string} */
    private function getResourceListMetadata(): array
    {
        if ($this->resourceListMetadata !== null) {
            return $this->resourceListMetadata;
        }

        $listId = $this->config->resourceListId ?? $this->discoverResourceListId();
        $fields = $this->call('lists.field.get', [
            'IBLOCK_TYPE_ID' => 'lists',
            'IBLOCK_ID' => $listId,
        ]);

        return $this->resourceListMetadata = [
            'listId' => $listId,
            'resource' => $this->config->resourceIdField
                ?? $this->findFieldId($fields, ['ID ресурса', 'ID ресурса онлайн-записи']),
            'stoa' => $this->config->serviceStationField
                ?? $this->findFieldId($fields, ['СТОА']),
            'user' => $this->config->masterUserIdField
                ?? $this->findFieldId($fields, ['ID экстранет-пользователя']),
            'active' => $this->config->activeField
                ?? $this->findFieldId($fields, ['Активность связи'], false),
        ];
    }

    private function discoverResourceListId(): int
    {
        $lists = $this->call('lists.get', ['IBLOCK_TYPE_ID' => 'lists']);
        $matches = [];
        foreach ($lists as $list) {
            if (!is_array($list) || trim((string) ($list['NAME'] ?? '')) !== $this->config->resourceListName) {
                continue;
            }
            if (($list['ACTIVE'] ?? 'Y') === 'N') {
                continue;
            }
            $matches[] = $this->positiveInt($list['ID'] ?? null, 'Некорректный ID универсального списка');
        }

        if (count($matches) !== 1) {
            throw new BookingDataException(sprintf(
                'Ожидался один активный список "%s", получено: %d',
                $this->config->resourceListName,
                count($matches),
            ));
        }

        return $matches[0];
    }

    private function findFieldId(array $fields, array $names, bool $required = true): ?string
    {
        $matches = [];
        foreach ($fields as $key => $field) {
            if (!is_array($field) || !in_array(trim((string) ($field['NAME'] ?? '')), $names, true)) {
                continue;
            }

            $fieldId = trim((string) ($field['ID'] ?? $key));
            if ($fieldId !== '') {
                $matches[] = str_starts_with($fieldId, 'PROPERTY_') ? $fieldId : 'PROPERTY_' . $fieldId;
            }
        }

        $matches = array_values(array_unique($matches));
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (!$required && $matches === []) {
            return null;
        }

        throw new BookingDataException(sprintf(
            'Не удалось однозначно определить поле списка "%s"',
            implode('"/"', $names),
        ));
    }

    private function isActiveListItem(array $item, ?string $activeField): bool
    {
        if ($activeField === null) {
            return true;
        }

        $value = $this->singlePropertyValue($item, $activeField);

        return !in_array(trim((string) $value), ['', '0', 'N', 'Нет', 'нет', 'false', 'False'], true);
    }

    private function singlePropertyValue(array $item, string $fieldId): mixed
    {
        $values = [];
        $this->flattenScalars($item[$fieldId] ?? null, $values);
        $values = array_values(array_unique(array_filter(
            array_map(static fn(mixed $value): string => trim((string) $value), $values),
            static fn(string $value): bool => $value !== '',
        )));

        if (count($values) > 1) {
            throw new BookingDataException(sprintf('Поле %s содержит несколько значений', $fieldId));
        }

        return $values[0] ?? null;
    }

    private function flattenScalars(mixed $value, array &$result): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->flattenScalars($item, $result);
            }
            return;
        }
        if (is_scalar($value)) {
            $result[] = $value;
        }
    }

    private function dateTime(mixed $value, string $label): DateTimeImmutable
    {
        $value = $this->array($value, sprintf('Отсутствует дата %s бронирования', $label));
        $timestamp = $this->positiveInt($value['timestamp'] ?? null, sprintf('Некорректная дата %s', $label));
        $timezoneName = trim((string) ($value['timezone'] ?? '')) ?: date_default_timezone_get();

        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            throw new BookingDataException(sprintf('Некорректный часовой пояс даты %s', $label));
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    }

    private function call(string $method, array $parameters): array
    {
        return $this->b24->core
            ->call($method, $parameters)
            ->getResponseData()
            ->getResult();
    }

    private function array(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new BookingDataException($message);
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $message): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new BookingDataException($message);
        }

        return (int) $value;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->positiveInt($value, 'Некорректное значение технического поля сделки');
    }

    private function requiredString(mixed $value, string $message): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new BookingDataException($message);
        }

        return $value;
    }
}
