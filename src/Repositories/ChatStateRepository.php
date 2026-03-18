<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Logger;
use App\Support\Database;
use Exception;
use PDO;
use DateTimeImmutable;
use DateInterval;
use RuntimeException;
use Throwable;

readonly class ChatStateRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function getActiveByChatId(int|string $chatId): ?array
    {
        $sql = "
            SELECT
                chat_id,
                user_id,
                state,
                context,
                created_at,
                updated_at,
                expires_at
            FROM chat_states
            WHERE chat_id = :chat_id
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'chat_id' => $chatId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['context'] = $row['context']
            ? json_decode((string)$row['context'], true)
            : null;

        return $row;
    }

    public function getState(int|string $chatId): ?string
    {
        $row = $this->getActiveByChatId($chatId);

        return $row['state'] ?? null;
    }

    public function hasActiveState(int|string $chatId): bool
    {
        return $this->getActiveByChatId($chatId) !== null;
    }

    public function saveState(
        int|string $chatId,
        string $state,
        ?int $userId = null,
        ?array $context = null,
        ?DateTimeImmutable $expiresAt = null
    ): void {
        $sql = "
            INSERT INTO chat_states (
                chat_id,
                user_id,
                state,
                context,
                created_at,
                updated_at,
                expires_at
            )
            VALUES (
                :chat_id,
                :user_id,
                :state,
                CAST(:context AS JSONB),
                NOW(),
                NOW(),
                :expires_at
            )
            ON CONFLICT (chat_id)
            DO UPDATE SET
                user_id = EXCLUDED.user_id,
                state = EXCLUDED.state,
                context = EXCLUDED.context,
                updated_at = NOW(),
                expires_at = EXCLUDED.expires_at
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'chat_id'    => $chatId,
            'user_id'    => $userId,
            'state'      => $state,
            'context'    => $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:sP'),
        ]);
    }

    public function saveStateForMinutes(
        int|string $chatId,
        string $state,
        int $minutes,
        ?int $userId = null,
        ?array $context = null
    ): void {
        try {
            $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT' . $minutes . 'M'));

            $this->saveState($chatId, $state, $userId, $context, $expiresAt);
        } catch (Throwable $e) {
            throw new RuntimeException(
                '[ChatStateRepository->saveStateForMinutes] Error creating $expiresAt -> ' . $e->getMessage()
            );
        }
    }

    public function updateContext(int|string $chatId, array $context): void
    {
        $current = $this->getActiveByChatId($chatId);

        if (!$current) {
            return;
        }

        $mergedContext = array_merge($current['context'] ?? [], $context);

        $sql = "
            UPDATE chat_states
            SET context = CAST(:context AS JSONB),
                updated_at = NOW()
            WHERE chat_id = :chat_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'chat_id' => $chatId,
            'context' => json_encode($mergedContext, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function clearState(int|string $chatId): void
    {
        try {
            $sql = "DELETE FROM chat_states 
                WHERE chat_id = :chat_id;
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'chat_id' => $chatId,
            ]);
        } catch (Exception $e) {
            Logger::error('[ChatStateRepository clearState] Error clearing chat state: ]', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => [
                    'chat_id' => $chatId,
                ]
            ]);
        }
    }

    public function clearExpiredStates(): int
    {
        $sql = "
            DELETE FROM chat_states
            WHERE expires_at IS NOT NULL
              AND expires_at <= NOW()
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
