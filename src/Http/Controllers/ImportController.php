<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\B24Service;

readonly class ImportController
{
    public function __construct(private B24Service $b24Service) {}

    private function json(mixed $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        foreach ($headers as $k => $v) {
            header("$k: $v");
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public function importBirthdate(): void
    {
        $raw = file_get_contents('php://input') ?: '{}';
        $data = json_decode($raw, true) ?? [];

        if (!$data) {
            $this->json(['errors' => 'Нет данных'], 204);
            return;
        }

        $this->b24Service->importBirthdate($data);
        $this->json(['success' => 'Данные успешно импортированы']);
    }
}
