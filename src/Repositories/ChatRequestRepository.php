<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;
use RuntimeException;

readonly class ChatRequestRepository
{
    private const string TABLE = 'chat_requests';
    public const string STATUS_DRAFT = 'draft';
    public const string STATUS_SENT = 'sent';
    public const string STATUS_CANCELLED = 'cancelled';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function create(
        int|string $chatId,
        string $type,
        ?int $userId = null,
        ?string $address = null,
        ?string $phone = null,
        array $payload = []
    ): int {
        $sql = sprintf(
            'INSERT INTO %s (
                chat_id,
                user_id,
                type,
                status,
                address,
                phone,
                payload,
                created_at,
                updated_at
            ) VALUES (
                :chat_id,
                :user_id,
                :type,
                :status,
                :address,
                :phone,
                CAST(:payload AS JSONB),
                NOW(),
                NOW()
            )
            RETURNING id',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'type' => $type,
            'status' => self::STATUS_DRAFT,
            'address' => $address,
            'phone' => $phone,
            'payload' => $this->encodeJson($payload),
        ]);

        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new RuntimeException('Не удалось создать черновик заявки');
        }

        return (int)$id;
    }

    public function getById(int $id): ?array
    {
        $sql = sprintf(
            'SELECT
                id,
                chat_id,
                user_id,
                type,
                status,
                current_step,
                crm_entity_type,
                crm_entity_id,
                address,
                phone,
                payload,
                error_message,
                created_at,
                updated_at,
                completed_at,
                cancelled_at
            FROM %s
            WHERE id = :id
            LIMIT 1',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeRow($row) : null;
    }

    public function getActiveByChatId(int|string $chatId): ?array
    {
        $sql = sprintf(
            'SELECT
                id,
                chat_id,
                user_id,
                type,
                status,
                current_step,
                crm_entity_type,
                crm_entity_id,
                address,
                phone,
                payload,
                error_message,
                created_at,
                updated_at,
                completed_at,
                cancelled_at
            FROM %s
            WHERE chat_id = :chat_id
              AND status = :status
            ORDER BY created_at DESC
            LIMIT 1',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'chat_id' => $chatId,
            'status' => self::STATUS_DRAFT,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeRow($row) : null;
    }

    public function setAddress(int $requestId, string $address): void
    {
        $sql = sprintf(
            'UPDATE %s
            SET address = :address,
                updated_at = NOW()
            WHERE id = :id',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $requestId,
            'address' => trim($address),
        ]);
    }

    public function setPhone(int $requestId, string $phone): void
    {
        $sql = sprintf(
            'UPDATE %s
            SET phone = :phone,
                updated_at = NOW()
            WHERE id = :id',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $requestId,
            'phone' => trim($phone),
        ]);
    }

    public function setPayload(int $requestId, array $payload): void
    {
        $sql = sprintf(
            'UPDATE %s
            SET payload = CAST(:payload AS JSONB),
                updated_at = NOW()
            WHERE id = :id',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $requestId,
            'payload' => $this->encodeJson($payload),
        ]);
    }

    public function mergePayload(int $requestId, array $data): void
    {
        $request = $this->getById($requestId);

        if ($request === null) {
            throw new RuntimeException("Заявка {$requestId} не найдена");
        }

        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $mergedPayload = array_replace_recursive($payload, $data);

        $this->setPayload($requestId, $mergedPayload);
    }

    public function setStep(int $requestId, ?string $step): void
    {
        $sql = sprintf(
            'UPDATE %s
            SET current_step = :step,
                updated_at = NOW()
            WHERE id = :id',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $requestId,
            'step' => $step,
        ]);
    }

    public function setError(int $requestId, ?string $errorMessage): void
    {
        $sql = sprintf(
            'UPDATE %s
            SET error_message = :error_message,
                updated_at = NOW()
            WHERE id = :id',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $requestId,
            'error_message' => $errorMessage,
        ]);
    }

    public function markSent(
        int $requestId,
        int|string|null $crmEntityId = null,
        ?string $crmEntityType = null
    ): void {
        $sql = sprintf(
            'UPDATE %s
            SET status = :status,
                crm_entity_id = :crm_entity_id,
                crm_entity_type = :crm_entity_type,
                completed_at = NOW(),
                updated_at = NOW(),
                error_message = NULL
            WHERE id = :id',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $requestId,
            'status' => self::STATUS_SENT,
            'crm_entity_id' => $crmEntityId,
            'crm_entity_type' => $crmEntityType,
        ]);
    }

    public function cancel(int $requestId): void
    {
        $sql = sprintf(
            'UPDATE %s
            SET status = :status,
                cancelled_at = NOW(),
                updated_at = NOW()
            WHERE id = :id',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $requestId,
            'status' => self::STATUS_CANCELLED,
        ]);
    }

    public function deleteOldDrafts(int $hours): int
    {
        if ($hours <= 0) {
            throw new RuntimeException('Параметр $hours должен быть больше 0');
        }

        $sql = sprintf(
            'DELETE FROM %s
            WHERE status = :status
              AND created_at < NOW() - (:hours || \' hours\')::interval',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'status' => self::STATUS_DRAFT,
            'hours' => $hours,
        ]);

        return $stmt->rowCount();
    }

    public function deleteCancelledOlderThanDays(int $days): int
    {
        if ($days <= 0) {
            throw new RuntimeException('Параметр $days должен быть больше 0');
        }

        $sql = sprintf(
            'DELETE FROM %s
            WHERE status = :status
              AND cancelled_at IS NOT NULL
              AND cancelled_at < NOW() - (:days || \' days\')::interval',
            self::TABLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'status' => self::STATUS_CANCELLED,
            'days' => $days,
        ]);

        return $stmt->rowCount();
    }

    public function updateFields(int $requestId, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $allowedFields = [
            'address',
            'phone',
            'current_step',
            'crm_entity_type',
            'crm_entity_id',
            'error_message',
            'status',
        ];

        $setParts = [];
        $params = ['id' => $requestId];

        foreach ($fields as $field => $value) {
            if (!in_array($field, $allowedFields, true)) {
                continue;
            }

            $paramName = 'field_' . $field;
            $setParts[] = sprintf('%s = :%s', $field, $paramName);
            $params[$paramName] = $value;
        }

        if ($setParts === []) {
            return;
        }

        $setParts[] = 'updated_at = NOW()';

        $sql = sprintf(
            'UPDATE %s
            SET %s
            WHERE id = :id',
            self::TABLE,
            implode(",\n                ", $setParts)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function normalizeRow(array $row): array
    {
        $row['id'] = isset($row['id']) ? (int)$row['id'] : null;
        $row['chat_id'] = $row['chat_id'] ?? null;
        $row['user_id'] = isset($row['user_id']) ? (int)$row['user_id'] : null;
        $row['crm_entity_id'] = isset($row['crm_entity_id']) && $row['crm_entity_id'] !== null
            ? (int)$row['crm_entity_id']
            : null;

        $row['payload'] = $this->decodeJson($row['payload'] ?? null);

        return $row;
    }

    private function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Не удалось закодировать payload в JSON');
        }

        return $json;
    }

    private function decodeJson(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        if (is_array($json)) {
            return $json;
        }

        $decoded = json_decode((string)$json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
