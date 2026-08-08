<?php
declare(strict_types=1);

namespace App\Booking;

final readonly class BookingAutomationConfig
{
    public function __construct(
        public int $workflowTemplateId = 724,
        public string $currentBookingField = 'UF_CRM_1786012014',
        public string $masterTaskField = 'UF_CRM_1786012024',
        public string $resourceListName = 'Связь ресурсов СТОА',
        public ?int $resourceListId = null,
        public ?string $resourceIdField = null,
        public ?string $serviceStationField = null,
        public ?string $masterUserIdField = null,
        public ?string $activeField = null,
        public bool $deleteDuplicateBookings = false,
    ) {
    }

    public static function fromEnvironment(array $environment): self
    {
        return new self(
            workflowTemplateId: self::positiveInt($environment['B24_BOOKING_WORKFLOW_TEMPLATE_ID'] ?? null) ?? 724,
            currentBookingField: self::string($environment['B24_BOOKING_CURRENT_ID_FIELD'] ?? null) ?? 'UF_CRM_1786012014',
            masterTaskField: self::string($environment['B24_BOOKING_MASTER_TASK_ID_FIELD'] ?? null) ?? 'UF_CRM_1786012024',
            resourceListName: self::string($environment['B24_BOOKING_RESOURCE_LIST_NAME'] ?? null) ?? 'Связь ресурсов СТОА',
            resourceListId: self::positiveInt($environment['B24_BOOKING_RESOURCE_LIST_ID'] ?? null),
            resourceIdField: self::propertyId($environment['B24_BOOKING_RESOURCE_ID_FIELD'] ?? null),
            serviceStationField: self::propertyId($environment['B24_BOOKING_STOA_FIELD'] ?? null),
            masterUserIdField: self::propertyId($environment['B24_BOOKING_MASTER_USER_ID_FIELD'] ?? null),
            activeField: self::propertyId($environment['B24_BOOKING_LINK_ACTIVE_FIELD'] ?? null),
            deleteDuplicateBookings: filter_var(
                $environment['B24_BOOKING_DELETE_DUPLICATES'] ?? false,
                FILTER_VALIDATE_BOOL,
            ),
        );
    }

    private static function positiveInt(mixed $value): ?int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $validated === false ? null : $validated;
    }

    private static function string(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function propertyId(mixed $value): ?string
    {
        $value = self::string($value);
        if ($value === null) {
            return null;
        }

        return str_starts_with($value, 'PROPERTY_') ? $value : 'PROPERTY_' . $value;
    }
}
