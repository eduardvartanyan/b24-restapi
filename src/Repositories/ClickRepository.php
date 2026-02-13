<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;
use PDOException;
use RuntimeException;

class ClickRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function create(array $values): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO clicks (deal, contact) 
                VALUES (:deal, :contact);
            ");
            $stmt->execute([
                ':deal'    => $values['deal_id'],
                ':contact' => $values['contact_id'],
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                '[ClickRepository->create] Error inserting into clicks -> ' . $e->getMessage()
            );
        }
    }

    public function select(int $dealId, int $contactId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * 
                FROM clicks 
                WHERE deal = :deal AND contact = :contact;
            ");
            $stmt->execute([
                ':deal'    => (string) $dealId,
                ':contact' => (string) $contactId,
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) return null;

            return $result;
        } catch (PDOException $e) {
            throw new RuntimeException(
                '[ClickRepository->select] Error selecting from clicks -> ' . $e->getMessage()
            );
        }
    }

    public function getByDealId(int $dealId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * 
                FROM clicks 
                WHERE deal = :deal;
            ");
            $stmt->execute([
                ':deal' => (string) $dealId,
            ]);

            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$result) return null;

            return $result;
        } catch (PDOException $e) {
            throw new RuntimeException(
                '[ClickRepository->getByDealId] Error selecting from clicks -> ' . $e->getMessage()
            );
        }
    }
}
