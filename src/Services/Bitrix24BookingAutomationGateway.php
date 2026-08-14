<?php
declare(strict_types=1);

namespace App\Services;

use App\Booking\Booking;
use App\Booking\BookingAutomationConfig;
use App\Booking\BookingDataException;
use App\Booking\BookingSignature;
use App\Booking\ControlTask;
use App\Booking\DealBookingState;
use App\Booking\MasterTask;
use App\Booking\MaxRecipient;
use App\Booking\NotificationRecipient;
use App\Booking\ResourceAssignment;
use App\Booking\ServiceStation;
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
        private readonly MaxService $maxService,
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
        $resolvedDealId = $this->positiveInt($deal['ID'] ?? null, 'CRM-сделка не найдена');

        return new DealBookingState(
            dealId: $resolvedDealId,
            responsibleUserId: $this->positiveInt(
                $deal['ASSIGNED_BY_ID'] ?? null,
                'В CRM-сделке не указан ответственный',
            ),
            currentBookingId: $this->nullablePositiveInt($deal[$this->config->currentBookingField] ?? null),
            masterTaskId: $this->nullablePositiveInt($deal[$this->config->masterTaskField] ?? null),
            contactId: $this->resolveDealContactId($resolvedDealId, $deal),
            bookingSignature: $this->nullableString($deal[$this->config->bookingSignatureField] ?? null),
            serviceStationReference: $this->nullableString($deal[$this->config->dealServiceStationField] ?? null),
            controlTaskId: $this->nullablePositiveInt($deal[$this->config->controlTaskField] ?? null),
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
        $user = $this->getUser($userId);

        if (!in_array($user['ACTIVE'] ?? null, [true, 1, '1', 'Y'], true)) {
            throw new BookingDataException(sprintf('Экстранет-пользователь %d неактивен', $userId));
        }

        $userType = trim((string) ($user['USER_TYPE'] ?? ''));
        if ($userType !== '' && $userType !== 'extranet') {
            throw new BookingDataException(sprintf('Пользователь %d не является экстранет-пользователем', $userId));
        }

        $this->positiveInt(
            $user[$this->config->masterMaxIdField] ?? null,
            sprintf('У экстранет-пользователя %d не указан корректный Max ID', $userId),
        );
    }

    public function setCurrentBookingId(int $dealId, ?int $bookingId): void
    {
        $this->call('crm.deal.update', [
            'id' => $dealId,
            'fields' => [$this->config->currentBookingField => $bookingId === null ? '' : (string) $bookingId],
        ]);
    }

    public function setBookingSignature(int $dealId, ?string $signature): void
    {
        $this->updateDeal($dealId, [
            $this->config->bookingSignatureField => $signature === null ? '' : $signature,
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

    public function getMasterTask(int $taskId): MasterTask
    {
        $result = $this->call('tasks.task.get', [
            'taskId' => $taskId,
            'select' => ['ID', 'RESPONSIBLE_ID', 'DESCRIPTION'],
        ]);
        $task = $this->array($result['task'] ?? null, sprintf('Задача мастера %d не найдена', $taskId));

        return new MasterTask(
            id: $this->positiveInt($task['id'] ?? $task['ID'] ?? null, 'Некорректный ID задачи мастера'),
            responsibleUserId: $this->positiveInt(
                $task['responsibleId'] ?? $task['RESPONSIBLE_ID'] ?? null,
                'В задаче мастера не указан исполнитель',
            ),
            description: (string) ($task['description'] ?? $task['DESCRIPTION'] ?? ''),
        );
    }

    public function getControlTask(int $taskId): ControlTask
    {
        $result = $this->call('tasks.task.get', [
            'taskId' => $taskId,
            'select' => ['ID', 'DESCRIPTION', 'DEADLINE'],
        ]);
        $task = $this->array($result['task'] ?? null, sprintf('Контрольная задача %d не найдена', $taskId));
        $deadline = $this->nullableDateTime($task['deadline'] ?? $task['DEADLINE'] ?? null);

        return new ControlTask(
            id: $this->positiveInt($task['id'] ?? $task['ID'] ?? null, 'Некорректный ID контрольной задачи'),
            description: (string) ($task['description'] ?? $task['DESCRIPTION'] ?? ''),
            deadline: $deadline,
        );
    }

    public function getServiceStation(string $reference): ServiceStation
    {
        $companyId = $this->crmEntityId($reference, 'CO_', 'СТОА');
        $company = $this->call('crm.company.get', ['id' => $companyId]);

        return new ServiceStation(
            reference: 'CO_' . $companyId,
            name: $this->requiredString($company['TITLE'] ?? null, 'В карточке СТОА отсутствует название'),
            address: $this->resolveCompanyAddress($companyId, $company),
        );
    }

    public function getMasterRecipient(int $userId): MaxRecipient
    {
        $user = $this->getUser($userId);

        return new MaxRecipient(
            userId: $this->positiveInt(
                $user[$this->config->masterMaxIdField] ?? null,
                sprintf('У экстранет-пользователя %d не указан корректный Max ID', $userId),
            ),
            name: trim(implode(' ', array_filter([
                (string) ($user['NAME'] ?? ''),
                (string) ($user['LAST_NAME'] ?? ''),
            ]))),
        );
    }

    public function getClientRecipient(int $contactId): ?NotificationRecipient
    {
        $contact = $this->call('crm.contact.get', ['id' => $contactId]);
        $phones = (array) ($contact['PHONE'] ?? []);
        foreach ($phones as $phone) {
            $value = trim((string) (is_array($phone) ? ($phone['VALUE'] ?? '') : $phone));
            if ($value !== '') {
                return new NotificationRecipient($value);
            }
        }

        return null;
    }

    public function updateMasterTask(
        MasterTask $task,
        int $responsibleUserId,
        Booking $booking,
        ServiceStation $serviceStation,
    ): void {
        $description = $this->replaceTaskLine($task->description, 'СТОА:', $serviceStation->displayName());
        $description = $this->replaceTaskLine(
            $description,
            'Дата и время:',
            $booking->startsAt->format('d.m.Y H:i:s'),
        );

        $this->call('tasks.task.update', [
            'taskId' => $task->id,
            'fields' => [
                'RESPONSIBLE_ID' => $responsibleUserId,
                'DESCRIPTION' => $description,
                'DEADLINE' => $booking->endsAt->format(DateTimeInterface::ATOM),
            ],
        ]);
    }

    public function addMasterTaskComment(int $taskId, string $message): void
    {
        $this->call('task.commentitem.add', [
            'TASKID' => $taskId,
            'FIELDS' => ['POST_MESSAGE' => $message],
        ]);
    }

    public function updateControlTask(
        ControlTask $task,
        Booking $booking,
        BookingSignature $previousBooking,
    ): void {
        $fields = [
            'DESCRIPTION' => $this->replaceTaskLine(
                $task->description,
                'Дата и время дефектовки:',
                $booking->startsAt->format('d.m.Y H:i:s'),
            ),
        ];

        if ($task->deadline !== null) {
            $deadlineShift = $booking->endsAt->getTimestamp() - $previousBooking->endsAt->getTimestamp();
            $fields['DEADLINE'] = $task->deadline
                ->modify(sprintf('%+d seconds', $deadlineShift))
                ->format(DateTimeInterface::ATOM);
        }

        $this->call('tasks.task.update', [
            'taskId' => $task->id,
            'fields' => $fields,
        ]);
    }

    public function addControlTaskComment(int $taskId, string $message): void
    {
        $this->addMasterTaskComment($taskId, $message);
    }

    public function updateDealServiceStation(int $dealId, string $reference): void
    {
        $this->updateDeal($dealId, [$this->config->dealServiceStationField => $reference]);
    }

    public function addDealTimelineComment(int $dealId, string $message): void
    {
        $this->call('crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => $dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => $message,
            ],
        ]);
    }

    public function sendCascadeMessage(int $contactId, NotificationRecipient $recipient, string $message): void
    {
        try {
            $this->startContactWorkflow($contactId, $this->config->contactCascadeWorkflowTemplateId, [
                'number' => $recipient->phone,
                'text' => $message,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Не удалось запустить каскадный процесс %d для контакта %d: %s',
                $this->config->contactCascadeWorkflowTemplateId,
                $contactId,
                $e->getMessage(),
            ), (int) $e->getCode(), $e);
        }
    }

    public function sendMaxMessage(MaxRecipient $recipient, string $message): void
    {
        $result = $this->maxService->sendMessage(message: $message, userId: $recipient->userId);
        if (($result['status'] ?? 500) < 200 || ($result['status'] ?? 500) >= 300) {
            throw new \RuntimeException(sprintf(
                'Не удалось отправить сообщение пользователю MAX %d: %s',
                $recipient->userId,
                (string) ($result['body'] ?? 'неизвестная ошибка'),
            ));
        }
    }

    public function rescheduleMasterReminder(
        int $dealId,
        MaxRecipient $recipient,
        int $taskId,
        Booking $booking,
        string $message,
    ): void {
        $this->terminateDealWorkflows($dealId, $this->config->masterReminderWorkflowTemplateId);
        $this->startDealWorkflow($dealId, $this->config->masterReminderWorkflowTemplateId, [
            // Идентификатор параметра сохранен для совместимости с настроенным процессом 738.
            'number' => (string) $recipient->userId,
            'text' => $message,
            'pause_to' => $booking->endsAt->modify('+1 hour')->format(DateTimeInterface::ATOM),
            'task_id' => $taskId,
        ]);
    }

    public function rescheduleClientReminder(
        int $dealId,
        ?NotificationRecipient $recipient,
        Booking $booking,
        string $message,
    ): void {
        $this->terminateDealWorkflows($dealId, $this->config->clientReminderWorkflowTemplateId);
        $sendAt = $booking->startsAt->modify('-24 hours');
        if ($recipient === null || $sendAt <= new DateTimeImmutable()) {
            return;
        }

        $this->startDealWorkflow($dealId, $this->config->clientReminderWorkflowTemplateId, [
            'number' => $recipient->phone,
            'text' => $message,
            'pause_to' => $sendAt->format(DateTimeInterface::ATOM),
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

    private function getUser(int $userId): array
    {
        $users = $this->call('user.get', [
            'FILTER' => ['ID' => $userId],
            'ADMIN_MODE' => true,
        ]);

        foreach ($users as $candidate) {
            if (is_array($candidate) && (int) ($candidate['ID'] ?? 0) === $userId) {
                return $candidate;
            }
        }

        throw new BookingDataException(sprintf('Экстранет-пользователь %d не найден', $userId));
    }

    private function resolveCompanyAddress(int $companyId, array $company): string
    {
        $address = $this->formatAddress([
            $company['ADDRESS'] ?? null,
            $company['ADDRESS_2'] ?? null,
            $company['ADDRESS_CITY'] ?? null,
            $company['ADDRESS_REGION'] ?? null,
            $company['ADDRESS_PROVINCE'] ?? null,
            $company['ADDRESS_COUNTRY'] ?? null,
            $company['ADDRESS_POSTAL_CODE'] ?? null,
        ]);
        if ($address !== '') {
            return $address;
        }

        $requisites = $this->call('crm.requisite.list', [
            'select' => ['ID'],
            'filter' => [
                'ENTITY_TYPE_ID' => 4,
                'ENTITY_ID' => $companyId,
            ],
            'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
        ]);

        foreach ($requisites as $requisite) {
            if (!is_array($requisite)) {
                continue;
            }

            $requisiteId = $this->nullablePositiveInt($requisite['ID'] ?? null);
            if ($requisiteId === null) {
                continue;
            }

            $addresses = $this->call('crm.address.list', [
                'select' => [
                    'TYPE_ID',
                    'ADDRESS_1',
                    'ADDRESS_2',
                    'CITY',
                    'REGION',
                    'PROVINCE',
                    'COUNTRY',
                    'POSTAL_CODE',
                ],
                'filter' => [
                    'ENTITY_TYPE_ID' => 8,
                    'ENTITY_ID' => $requisiteId,
                ],
                'order' => ['TYPE_ID' => 'ASC'],
            ]);

            foreach ($addresses as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $address = $this->formatAddress([
                    $candidate['ADDRESS_1'] ?? null,
                    $candidate['ADDRESS_2'] ?? null,
                    $candidate['CITY'] ?? null,
                    $candidate['REGION'] ?? null,
                    $candidate['PROVINCE'] ?? null,
                    $candidate['COUNTRY'] ?? null,
                    $candidate['POSTAL_CODE'] ?? null,
                ]);
                if ($address !== '') {
                    return $address;
                }
            }
        }

        throw new BookingDataException(sprintf('В карточке СТОА %d отсутствует адрес', $companyId));
    }

    private function formatAddress(array $parts): string
    {
        $normalized = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && !in_array($part, $normalized, true)) {
                $normalized[] = $part;
            }
        }

        return implode(', ', $normalized);
    }

    private function resolveDealContactId(int $dealId, array $deal): ?int
    {
        $contactId = $this->nullablePositiveInt($deal['CONTACT_ID'] ?? null);
        if ($contactId !== null) {
            return $contactId;
        }

        $contactIds = array_values(array_unique(array_filter(array_map(
            fn(mixed $value): ?int => $this->nullablePositiveInt($value),
            (array) ($deal['CONTACT_IDS'] ?? []),
        ))));
        if (count($contactIds) === 1) {
            return $contactIds[0];
        }

        $items = $this->call('crm.deal.contact.items.get', ['id' => $dealId]);
        $primaryIds = [];
        $allIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemContactId = $this->nullablePositiveInt($item['CONTACT_ID'] ?? null);
            if ($itemContactId === null) {
                continue;
            }

            $allIds[] = $itemContactId;
            if (in_array($item['IS_PRIMARY'] ?? null, [true, 1, '1', 'Y'], true)) {
                $primaryIds[] = $itemContactId;
            }
        }

        $primaryIds = array_values(array_unique($primaryIds));
        if (count($primaryIds) === 1) {
            return $primaryIds[0];
        }

        $allIds = array_values(array_unique($allIds));
        if (count($allIds) === 1) {
            return $allIds[0];
        }
        if ($allIds === []) {
            return null;
        }

        throw new BookingDataException(sprintf(
            'У сделки %d несколько связанных контактов, но основной контакт не определен',
            $dealId,
        ));
    }

    private function updateDeal(int $dealId, array $fields): void
    {
        $this->call('crm.deal.update', ['id' => $dealId, 'fields' => $fields]);
    }

    private function startDealWorkflow(int $dealId, int $templateId, array $parameters): void
    {
        $this->call('bizproc.workflow.start', [
            'TEMPLATE_ID' => $templateId,
            'DOCUMENT_ID' => ['crm', 'CCrmDocumentDeal', 'DEAL_' . $dealId],
            'PARAMETERS' => $parameters,
        ]);
    }

    private function startContactWorkflow(int $contactId, int $templateId, array $parameters): void
    {
        $this->call('bizproc.workflow.start', [
            'TEMPLATE_ID' => $templateId,
            'DOCUMENT_ID' => ['crm', 'CCrmDocumentContact', 'CONTACT_' . $contactId],
            'PARAMETERS' => $parameters,
        ]);
    }

    private function terminateDealWorkflows(int $dealId, int $templateId): void
    {
        $instances = $this->call('bizproc.workflow.instances', [
            'SELECT' => ['ID'],
            'FILTER' => [
                'MODULE_ID' => 'crm',
                'ENTITY' => 'CCrmDocumentDeal',
                'DOCUMENT_ID' => 'DEAL_' . $dealId,
                'TEMPLATE_ID' => $templateId,
            ],
        ]);

        foreach ($instances as $instance) {
            $id = trim((string) (is_array($instance) ? ($instance['ID'] ?? '') : ''));
            if ($id !== '') {
                $this->call('bizproc.workflow.terminate', [
                    'ID' => $id,
                    'STATUS' => 'Онлайн-запись изменена, ожидание устарело.',
                ]);
            }
        }
    }

    private function replaceTaskLine(string $description, string $label, string $value): string
    {
        $pattern = '/^' . preg_quote($label, '/') . '.*$/mu';
        $replacement = $label . ' ' . $value;
        if (preg_match($pattern, $description) === 1) {
            return (string) preg_replace($pattern, $replacement, $description, 1);
        }

        return $replacement . PHP_EOL . $description;
    }

    private function crmEntityId(string $reference, string $prefix, string $label): int
    {
        $value = preg_replace('/^' . preg_quote($prefix, '/') . '/i', '', trim($reference));

        return $this->positiveInt($value, sprintf('Некорректная привязка к карточке %s', $label));
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

    private function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new BookingDataException('В задаче указан некорректный крайний срок');
        }
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

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
