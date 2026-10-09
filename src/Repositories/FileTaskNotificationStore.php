<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\TaskNotificationStore;
use RuntimeException;

final readonly class FileTaskNotificationStore implements TaskNotificationStore
{
    public function __construct(private string $directory)
    {
    }

    public function executeOnce(int $taskId, callable $operation): bool
    {
        if ($taskId <= 0) {
            throw new RuntimeException('Task ID must be positive');
        }

        $this->ensureDirectory();
        $lockPath = $this->directory . '/' . $taskId . '.lock';
        $markerPath = $this->directory . '/' . $taskId . '.sent';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new RuntimeException(sprintf('Cannot open task notification lock: %s', $lockPath));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(sprintf('Cannot lock task notification: %d', $taskId));
            }
            if (is_file($markerPath)) {
                return false;
            }

            $operation();
            if (file_put_contents($markerPath, date(DATE_ATOM) . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException(sprintf('Cannot persist task notification marker: %d', $taskId));
            }

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }
        if (!mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Cannot create task notification directory: %s', $this->directory));
        }
    }
}
