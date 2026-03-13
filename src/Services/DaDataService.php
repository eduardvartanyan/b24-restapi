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
        try {
            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/geolocate/address',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode([
                    'lat'           => $latitude,
                    'lon'           => $longitude,
                    'radius_meters' => 100,
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Token ' . $this->token,
                    'X-Secret: ' . $this->secret,
                ],
            ]);

            $response = curl_exec($curl);
            curl_close($curl);

            Logger::info('[DaDataService] getAddressByGeolocation', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'response' => $response,
            ]);

            $data = json_decode($response, true);

            if (
                isset($data['suggestions'])
                && is_array($data['suggestions'])
                && count($data['suggestions'])
            ) {
                return $data['suggestions'][0]['value'];
            }
        } catch (Throwable $e) {
            Logger::error('[DaDataService] getAddressByGeolocation', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]
            ]);
        }

        return false;
    }
}