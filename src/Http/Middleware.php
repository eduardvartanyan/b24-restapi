<?php
declare(strict_types=1);

namespace App\Http;

use App\Helpers\Logger;

class Middleware
{
    static public function check(): void
    {
        $headers = getallheaders();

        Logger::info('Заголовки запроса', ['headers' => $headers]);

        $token = $headers['Token'] ?? $_GET['token'] ?? $_GET['Token'] ?? null;

        if ($token === null || trim((string)$token) === '') {
            http_response_code(401);
            echo json_encode(['error' => 'Осутствует токен'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $token = str_replace('Bearer ', '', (string)$token);
        if ($token !== $_ENV['TOKEN']) {
            http_response_code(401);
            echo json_encode(['error' => 'Токен не прошел проверку'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
