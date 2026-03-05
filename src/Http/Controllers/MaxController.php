<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\MaxService;

readonly class MaxController
{
    public function __construct(private MaxService $maxService) {}

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $result = $this->maxService->handle($raw);

        http_response_code($result['status']);
        echo $result['body'];
    }
}
