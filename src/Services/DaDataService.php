<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use Throwable;

readonly class DaDataService
{
    public function __construct(
        private string $token,
        private string $secret
    ) { }

    public function getAddressByGeolocation(float $latitude, float $longitude): bool|string
    {
        $data = $this->apiRequest(
            'https://suggestions.dadata.ru/suggestions/api/4_1/rs/geolocate/address',
            [
                'lat'           => $latitude,
                'lon'           => $longitude,
                'radius_meters' => 100,
            ]
        );

        if (
            isset($data['suggestions'])
            && is_array($data['suggestions'])
            && count($data['suggestions'])
        ) {
            return $data['suggestions'][0]['value'];
        }

        return false;
    }

    public function cleanAddress(string $str): bool|string
    {
        $data = $this->apiRequest(
            'https://cleaner.dadata.ru/api/v1/clean/address',
            ['"Иркутск ' . $str . '"'],
        );

        if (
            count($data)
            && isset($data[0]['result'])
            && $data[0]['result'] !== 'г Иркутск'
        ) {
            return $data[0]['result'];
        }

        return false;
    }

    private function apiRequest(string $url, array $postFields): bool|array
    {
        try {

            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($postFields),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Token ' . $this->token,
                    'X-Secret: ' . $this->secret,
                ],
            ]);

            $response = curl_exec($curl);
            curl_close($curl);

            Logger::info('[DaDataService] apiRequest', [
                'url' => $url,
                'post_fields' => $postFields,
                'response' => $response,
            ]);

            return json_decode($response, true);
        } catch (Throwable $e) {
            Logger::error('[DaDataService] apiRequest', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
            ]);
        }

        return false;
    }
}