<?php
declare(strict_types=1);

use App\Contracts\MaxMessageSender;
use App\Contracts\TaskNotificationGateway;
use App\Contracts\TaskNotificationStore;
use App\Handlers\TaskCreatedEventHandler;
use App\Repositories\FileTaskNotificationStore;
use App\Task\CreatedTask;
use App\Task\TaskNotificationConfig;
use App\Task\TaskNotificationRecipient;

require_once __DIR__ . '/../vendor/autoload.php';

final class FakeTaskNotificationGateway implements TaskNotificationGateway
{
    public TaskNotificationRecipient $recipient;

    public function __construct()
    {
        $this->recipient = new TaskNotificationRecipient(610, true, null, 'Петр Петров');
    }

    public function getTask(int $taskId): CreatedTask
    {
        return new CreatedTask(
            $taskId,
            'Проверить документы',
            610,
            'Иван Иванов',
            new DateTimeImmutable('2026-10-10 15:00:00+08:00'),
        );
    }

    public function getNotificationRecipient(int $userId): TaskNotificationRecipient
    {
        return $this->recipient;
    }
}

final class FakeTaskNotificationStore implements TaskNotificationStore
{
    /** @var array<int, true> */
    public array $sent = [];

    public function executeOnce(int $taskId, callable $operation): bool
    {
        if (isset($this->sent[$taskId])) {
            return false;
        }
        $operation();
        $this->sent[$taskId] = true;
        return true;
    }
}

final class FakeMaxMessageSender implements MaxMessageSender
{
    public array $messages = [];
    public bool $fail = false;

    public function sendToUser(int $userId, string $message): void
    {
        if ($this->fail) {
            throw new RuntimeException('MAX unavailable');
        }
        $this->messages[] = [$userId, $message];
    }
}

function taskPayload(int $taskId, string $event = 'ONTASKADD'): array
{
    return [
        'event' => $event,
        'data' => ['FIELDS_AFTER' => ['ID' => (string) $taskId]],
    ];
}

function assertTaskSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual: ' . var_export($actual, true));
    }
}

$tests = [];

$tests['sends task notification to responsible extranet user'] = static function (): void {
    $gateway = new FakeTaskNotificationGateway();
    $gateway->recipient = new TaskNotificationRecipient(610, true, 381250, 'Петр Петров');
    $store = new FakeTaskNotificationStore();
    $sender = new FakeMaxMessageSender();
    $handler = new TaskCreatedEventHandler(
        $gateway,
        $store,
        $sender,
        new TaskNotificationConfig(taskUrlTemplate: 'https://example.test/user/%d/task/%d/'),
    );

    $result = $handler->handle(taskPayload(123));

    assertTaskSame('sent', $result['status'], 'Notification status is invalid');
    assertTaskSame(1, count($sender->messages), 'MAX message was not sent');
    assertTaskSame(381250, $sender->messages[0][0], 'MAX message was sent to a wrong user');
    assertTaskSame(true, str_contains($sender->messages[0][1], 'Проверить документы'), 'Task title is missing');
    assertTaskSame(true, str_contains($sender->messages[0][1], '10.10.2026 15:00'), 'Deadline is missing');
    assertTaskSame(
        true,
        str_contains($sender->messages[0][1], 'https://example.test/user/610/task/123/'),
        'Task URL is invalid',
    );
};

$tests['skips employee without Max ID'] = static function (): void {
    $gateway = new FakeTaskNotificationGateway();
    $store = new FakeTaskNotificationStore();
    $sender = new FakeMaxMessageSender();
    $handler = new TaskCreatedEventHandler($gateway, $store, $sender, new TaskNotificationConfig());

    $result = $handler->handle(taskPayload(123));

    assertTaskSame('skipped_no_max_id', $result['status'], 'Missing Max ID was not skipped');
    assertTaskSame([], $sender->messages, 'Message was sent without Max ID');
    assertTaskSame([], $store->sent, 'Skipped notification was marked as sent');
};

$tests['skips non-extranet employee with Max ID'] = static function (): void {
    $gateway = new FakeTaskNotificationGateway();
    $gateway->recipient = new TaskNotificationRecipient(610, false, 381250, 'Петр Петров');
    $store = new FakeTaskNotificationStore();
    $sender = new FakeMaxMessageSender();
    $handler = new TaskCreatedEventHandler($gateway, $store, $sender, new TaskNotificationConfig());

    $result = $handler->handle(taskPayload(123));

    assertTaskSame('skipped_not_extranet', $result['status'], 'Internal employee was not skipped');
    assertTaskSame([], $sender->messages, 'Message was sent to an internal employee');
    assertTaskSame([], $store->sent, 'Skipped internal employee notification was marked as sent');
};

$tests['does not send duplicate event twice'] = static function (): void {
    $gateway = new FakeTaskNotificationGateway();
    $gateway->recipient = new TaskNotificationRecipient(610, true, 381250, 'Петр Петров');
    $store = new FakeTaskNotificationStore();
    $sender = new FakeMaxMessageSender();
    $handler = new TaskCreatedEventHandler($gateway, $store, $sender, new TaskNotificationConfig());

    $handler->handle(taskPayload(123));
    $result = $handler->handle(taskPayload(123));

    assertTaskSame('duplicate', $result['status'], 'Duplicate event was not detected');
    assertTaskSame(1, count($sender->messages), 'Duplicate event sent another message');
};

$tests['does not mark failed MAX message as sent'] = static function (): void {
    $gateway = new FakeTaskNotificationGateway();
    $gateway->recipient = new TaskNotificationRecipient(610, true, 381250, 'Петр Петров');
    $store = new FakeTaskNotificationStore();
    $sender = new FakeMaxMessageSender();
    $sender->fail = true;
    $handler = new TaskCreatedEventHandler($gateway, $store, $sender, new TaskNotificationConfig());

    try {
        $handler->handle(taskPayload(123));
        throw new RuntimeException('Expected MAX failure was not thrown');
    } catch (RuntimeException $e) {
        assertTaskSame('MAX unavailable', $e->getMessage(), 'Unexpected exception was thrown');
    }

    assertTaskSame([], $store->sent, 'Failed notification was marked as sent');
};

$tests['rejects unsupported event'] = static function (): void {
    $handler = new TaskCreatedEventHandler(
        new FakeTaskNotificationGateway(),
        new FakeTaskNotificationStore(),
        new FakeMaxMessageSender(),
        new TaskNotificationConfig(),
    );

    try {
        $handler->handle(taskPayload(123, 'ONTASKUPDATE'));
        throw new RuntimeException('Unsupported event was accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['file store persists only successful notification'] = static function (): void {
    $directory = sys_get_temp_dir() . '/forsite-task-notification-' . bin2hex(random_bytes(6));
    $store = new FileTaskNotificationStore($directory);
    $calls = 0;

    try {
        $sent = $store->executeOnce(123, static function () use (&$calls): void {
            $calls++;
        });
        $duplicate = $store->executeOnce(123, static function () use (&$calls): void {
            $calls++;
        });

        assertTaskSame(true, $sent, 'First file-store operation was not executed');
        assertTaskSame(false, $duplicate, 'File store did not detect duplicate operation');
        assertTaskSame(1, $calls, 'Duplicate operation callback was executed');

        try {
            $store->executeOnce(124, static function (): void {
                throw new RuntimeException('Operation failed');
            });
            throw new RuntimeException('Expected operation failure was not thrown');
        } catch (RuntimeException $e) {
            assertTaskSame('Operation failed', $e->getMessage(), 'Unexpected file-store exception');
        }
        assertTaskSame(
            true,
            $store->executeOnce(124, static function (): void {
            }),
            'Failed operation was incorrectly marked as completed',
        );
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
};

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    $passed++;
    echo "[OK] {$name}" . PHP_EOL;
}
echo sprintf('%d tests passed', $passed) . PHP_EOL;
