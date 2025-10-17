<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use Bitrix24\SDK\Services\ServiceBuilder;
use Throwable;

class B24Service
{
    private array $data;

    public function __construct(private readonly ServiceBuilder $b24) { }

    public function importBirthdate(array $data): void
    {
        Logger::info('Импорт дней рождений запущен', ['uri' => $_SERVER['REQUEST_URI']]);

        $this->data = $data;
        $contactIds = $this->getContactIds();
        $this->updateBirthdate($contactIds);
    }

    public function addDeal(array $fields): ?int
    {
        try {
            $result = $this->b24->getCRMScope()->deal()->add($fields);

            return $result->getId();
        } catch (Throwable $e) {
            Logger::error('Ошибка при добавлении сделки', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => $fields
            ]);
        }

        return null;
    }

    private function getContactIds(): array
    {
        $contactIds = [];

        foreach ($this->data as $item) {
            if ($item['name'] == '' || $item['phone'] == '') { continue; }

            try {
                $contactResult = $this->b24->getCRMScope()->contact()->list(
                    [],
                    [
                        '%NAME' => $item['name'],
                        'PHONE' => $item['phone'],
                    ],
                    ['ID'],
                    0
                );
                $contacts = $contactResult->getContacts();

                foreach ($contacts as $contact) {
                    $contactIds[$contact->ID] = $item['birthdate'];
                }
            } catch (Throwable $e) {
                Logger::error('Ошибка при получении ID контакта', [
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'message' => $e->getMessage(),
                    'data'    => $item
                ]);
            }
        }

        Logger::info('Получен массив с ID контактов', ['count' => count($contactIds)]);

        return $contactIds;
    }

    private function updateBirthdate(array $contactIds): void
    {
        try {
            foreach ($contactIds as $contactId => $birthdate) {
                $this->b24->getCRMScope()->contact()->update($contactId, [
                    'BIRTHDATE'            => $birthdate,
                    'UF_CRM_1657085677401' => $birthdate, // Еще какое-то день рождения
                ]);
            }
        } catch (Throwable $e) {
            Logger::error('Ошибка при обновлении дней рождений', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => $contactIds
            ]);
        }
    }
}
