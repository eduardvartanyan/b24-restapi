<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\TaskNotificationGateway;
use App\Helpers\Logger;
use App\Task\CreatedTask;
use App\Task\TaskNotificationConfig;
use App\Task\TaskNotificationRecipient;
use Bitrix24\SDK\Services\ServiceBuilder;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final readonly class Bitrix24TaskNotificationGateway implements TaskNotificationGateway
{
    public function __construct(
        private ServiceBuilder $b24,
        private TaskNotificationConfig $config,
    ) {
    }

    public function getTask(int $taskId): CreatedTask
    {
        $result = $this->call('tasks.task.get', [
            'taskId' => $taskId,
            'select' => ['ID', 'TITLE', 'RESPONSIBLE_ID', 'CREATED_BY', 'DEADLINE'],
        ]);
        $task = $result['task'] ?? null;
        if (!is_array($task)) {
            throw new RuntimeException(sprintf('Задача %d не найдена', $taskId));
        }

        $resolvedTaskId = $this->positiveInt($task['id'] ?? $task['ID'] ?? null, 'Некорректный ID задачи');
        $responsibleUserId = $this->positiveInt(
            $task['responsibleId'] ?? $task['RESPONSIBLE_ID'] ?? null,
            'В задаче не указан ответственный',
        );
        $creatorUserId = $this->positiveInt(
            $task['createdBy'] ?? $task['CREATED_BY'] ?? null,
            'В задаче не указан постановщик',
        );
        $title = trim((string) ($task['title'] ?? $task['TITLE'] ?? ''));
        if ($title === '') {
            throw new RuntimeException(sprintf('У задачи %d отсутствует название', $resolvedTaskId));
        }

        return new CreatedTask(
            id: $resolvedTaskId,
            title: $title,
            responsibleUserId: $responsibleUserId,
            creatorName: $this->getUserName($creatorUserId),
            deadline: $this->dateTime($task['deadline'] ?? $task['DEADLINE'] ?? null),
        );
    }

    public function getNotificationRecipient(int $userId): TaskNotificationRecipient
    {
        $user = $this->getUser($userId);
        $maxId = filter_var(
            $user[$this->config->maxIdField] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $extranet = strtolower(trim((string) ($user['USER_TYPE'] ?? ''))) === 'extranet';
        if (!$extranet) {
            Logger::info('B24 task MAX notification skipped for non-extranet user', [
                'user_id' => $userId,
                'user_type' => $user['USER_TYPE'] ?? null,
            ]);
        } elseif ($maxId === false) {
            Logger::info('B24 task MAX notification skipped because Max ID is missing', [
                'user_id' => $userId,
                'field' => $this->config->maxIdField,
            ]);
        }

        return new TaskNotificationRecipient(
            bitrixUserId: $userId,
            extranet: $extranet,
            maxId: $maxId === false ? null : (int) $maxId,
            name: $this->userName($user, $userId),
        );
    }

    private function getUserName(int $userId): string
    {
        try {
            return $this->userName($this->getUser($userId), $userId);
        } catch (Throwable $e) {
            Logger::error('B24 task creator name was not resolved', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
            return 'Пользователь #' . $userId;
        }
    }

    private function getUser(int $userId): array
    {
        $users = $this->call('user.get', [
            'FILTER' => ['ID' => $userId],
            'ADMIN_MODE' => true,
        ]);
        foreach ($users as $user) {
            if (is_array($user) && (int) ($user['ID'] ?? 0) === $userId) {
                return $user;
            }
        }

        throw new RuntimeException(sprintf('Пользователь %d не найден', $userId));
    }

    private function userName(array $user, int $userId): string
    {
        $name = trim(implode(' ', array_filter([
            trim((string) ($user['NAME'] ?? '')),
            trim((string) ($user['LAST_NAME'] ?? '')),
        ])));

        return $name !== '' ? $name : 'Пользователь #' . $userId;
    }

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new RuntimeException('В задаче указан некорректный крайний срок');
        }
    }

    private function positiveInt(mixed $value, string $message): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new RuntimeException($message);
        }

        return (int) $value;
    }

    private function call(string $method, array $parameters): array
    {
        return $this->b24->core
            ->call($method, $parameters)
            ->getResponseData()
            ->getResult();
    }
}
