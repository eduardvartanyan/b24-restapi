<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;

class OneCService
{
    private string $apiUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->apiUrl = $_ENV['ONEC_API_URL'];
        $this->apiKey = $_ENV['ONEC_API_KEY'];
    }

    public function fetchAccidents(string $dateFrom = ''): array
    {
        Logger::info('Запрос данных ДТП из 1С');

        if (empty($dateFrom)) {
            $dateFrom = date('YmdHis', strtotime('-2 days'));
        } else {
            $dateFrom = date('YmdHis', strtotime($dateFrom));
        }

        $curl = curl_init("{$this->apiUrl}/dtp/$dateFrom");
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic {$this->apiKey}",
                "Accept: application/json",
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        Logger::info("Сырые данные", ['data' => $response]);

        if ($error) {
            throw new \RuntimeException("Ошибка CURL при обращении к 1С: $error");
        }

        $data = json_decode($response, true);

        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Некорректный ответ от 1С (ожидался JSON)');
        }

        Logger::info('Данные ДТП успешно получены', ['count' => count($data)]);

        return $data;
    }
}