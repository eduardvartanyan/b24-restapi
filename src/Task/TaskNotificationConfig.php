<?php
declare(strict_types=1);

namespace App\Task;

final readonly class TaskNotificationConfig
{
    public function __construct(
        public string $maxIdField = 'UF_USR_1785748307515',
        public string $taskUrlTemplate = 'https://fs911.bitrix24.ru/company/personal/user/%d/tasks/task/view/%d/',
    ) {
    }

    public static function fromEnvironment(array $environment): self
    {
        return new self(
            maxIdField: self::string(
                $environment['B24_TASK_MAX_ID_FIELD']
                    ?? $environment['B24_BOOKING_MASTER_MAX_ID_FIELD']
                    ?? null,
            ) ?? 'UF_USR_1785748307515',
            taskUrlTemplate: self::string($environment['B24_TASK_URL_TEMPLATE'] ?? null)
                ?? 'https://fs911.bitrix24.ru/company/personal/user/%d/tasks/task/view/%d/',
        );
    }

    private static function string(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
