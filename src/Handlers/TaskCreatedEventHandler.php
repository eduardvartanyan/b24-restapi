<?php
declare(strict_types=1);

namespace App\Handlers;

use App\Contracts\MaxMessageSender;
use App\Contracts\TaskNotificationGateway;
use App\Contracts\TaskNotificationStore;
use App\Helpers\Logger;
use App\Task\CreatedTask;
use App\Task\TaskNotificationConfig;
use InvalidArgumentException;

final readonly class TaskCreatedEventHandler
{
    public function __construct(
        private TaskNotificationGateway $gateway,
        private TaskNotificationStore $store,
        private MaxMessageSender $sender,
        private TaskNotificationConfig $config,
    ) {
    }

    /** @return array{event: string, taskId: int, status: string} */
    public function handle(array $payload): array
    {
        $event = strtoupper(trim((string) ($payload['event'] ?? '')));
        if ($event !== 'ONTASKADD') {
            throw new InvalidArgumentException('Поддерживается только событие ONTASKADD');
        }

        $taskId = $this->positiveInt(
            $payload['data']['FIELDS_AFTER']['ID']
                ?? $payload['data']['FIELDS_AFTER']['id']
                ?? null,
            'В событии отсутствует корректный ID задачи',
        );
        $task = $this->gateway->getTask($taskId);
        $recipient = $this->gateway->getMaxRecipient($task->responsibleUserId);
        if ($recipient === null) {
            return ['event' => $event, 'taskId' => $taskId, 'status' => 'skipped_no_max_id'];
        }

        $sent = $this->store->executeOnce(
            $taskId,
            fn() => $this->sender->sendToUser($recipient->userId, $this->message($task)),
        );
        $status = $sent ? 'sent' : 'duplicate';
        Logger::info('B24 task MAX notification handled', [
            'task_id' => $taskId,
            'responsible_user_id' => $task->responsibleUserId,
            'status' => $status,
        ]);

        return ['event' => $event, 'taskId' => $taskId, 'status' => $status];
    }

    private function message(CreatedTask $task): string
    {
        $deadline = $task->deadline?->format('d.m.Y H:i') ?? 'не указан';
        $url = sprintf($this->config->taskUrlTemplate, $task->responsibleUserId, $task->id);

        return sprintf(
            "Вам назначена новая задача\n\n%s\n\nПостановщик: %s\nКрайний срок: %s\n\nОткрыть задачу:\n%s",
            $task->title,
            $task->creatorName,
            $deadline,
            $url,
        );
    }

    private function positiveInt(mixed $value, string $message): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new InvalidArgumentException($message);
        }

        return (int) $value;
    }
}
