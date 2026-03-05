<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;

class MaxService
{
    public function handle(string $raw): array
    {
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            Logger::error('Max webhook: invalid JSON', ['raw' => $raw]);
            return ['status' => 400, 'body' => 'Invalid payload'];
        }

        Logger::info('Max webhook: incoming update', [
            'payload' => $payload,
        ]);

        return ['status' => 200, 'body' => 'OK'];
    }
}
