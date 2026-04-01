<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;
use PDOException;
use RuntimeException;

readonly class ChatSourceRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /**
     * Создать запись о чате
     *
     * @param int $chatId
     * @param int $source
     * @return bool
     */
    public function create(int $chatId, int $source): bool
    {
        try {
            $sql = 'INSERT INTO chat_source (chat_id, source) VALUES (:chat_id, :source)';
            $stmt = $this->pdo->prepare($sql);

            return $stmt->execute([
                ':chat_id' => $chatId,
                ':source' => $source,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                '[ChatSourceRepository->create] ' . $e->getMessage()
            );
        }
    }

    /**
     * Получить запись по chat_id
     *
     * @param int $chatId
     * @return array{chat_id: int, source: int}|null
     */
    public function findByChatId(int $chatId): ?array
    {
        try {
            $sql = 'SELECT chat_id, source FROM chat_source WHERE chat_id = :chat_id LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':chat_id' => $chatId]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result !== false ? $result : null;
        } catch (PDOException $e) {
            throw new RuntimeException(
                '[ChatSourceRepository->findByChatId] ' . $e->getMessage()
            );
        }
    }

    /**
     * Получить source по chat_id
     *
     * @param int $chatId
     * @return int|null
     */
    public function getSource(int $chatId): ?int
    {
        try {
            $record = $this->findByChatId($chatId);

            return $record !== null ? $record['source'] : null;
        } catch (PDOException $e) {
            throw new RuntimeException(
                '[ChatSourceRepository->getSource] ' . $e->getMessage()
            );
        }
    }

    /**
     * Проверить существование записи
     *
     * @param int $chatId
     * @return bool
     */
    public function exists(int $chatId): bool
    {
        try {
            $sql = 'SELECT 1 FROM chat_source WHERE chat_id = :chat_id LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':chat_id' => $chatId]);

            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            throw new RuntimeException(
                '[ChatSourceRepository->exists] ' . $e->getMessage()
            );
        }
    }
}
