<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use Throwable;

readonly class DailyImportService
{
    public function __construct(
        private B24Service  $b24Service,
        private OneCService $oneCService
    ) { }

    public function run(): void
    {
        $accidents = $this->oneCService->fetchAccidents();

        foreach ($accidents as $accident) {
            try {
                $dealId = $this->b24Service->addDeal([
                    'CATEGORY_ID'          => 12, // 12 - Выплата по ОСАГО
                    'STAGE_ID'             => 'C12:NEW', // C12:NEW - Выгружена
                    'UF_CRM_1636471851086' => $accident['contract_number'] ?? '',
                    'UF_CRM_1574325138229' => $accident['driver'] ?? '',
                    'UF_CRM_1574325151082' => $accident['driver_phone'] ?? '',
                    'UF_CRM_1510309410'    => $accident['car'] ?? '',
                    'UF_CRM_1510894280'    => $accident['car_number'] ?? '',
                    'UF_CRM_1558627536'    => $accident['issue_year'] ?? '',
                    'UF_CRM_1561010437'    => $accident['owner'] ?? '',
                    'UF_CRM_1645478185'    => $accident['address'] ?? '',
                    'UF_CRM_1517203914'    => $accident['date'] ?? '',
                ]);

                Logger::info("Создана сделка $dealId", ['data' => $accident]);
            } catch (Throwable $e) {
                Logger::error('Ошибка при добавлении ДТП', [
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'message' => $e->getMessage(),
                    'data'    => $accident
                ]);
            }
        }
    }
}