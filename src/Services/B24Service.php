<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use Bitrix24\SDK\Core\Exceptions\BaseException;
use Bitrix24\SDK\Core\Exceptions\TransportException;
use Bitrix24\SDK\Services\ServiceBuilder;
use Psr\Log\LoggerTrait;

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

    private function getContactIds(): array
    {
        $contactIds = [];
        try {
            foreach ($this->data as $item) {
                if ($item['name'] == '' || $item['phone'] == '') { continue; }

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
            }
        } catch (BaseException|TransportException $e) {
            Logger::error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        Logger::info('Получен массив с ID контактов', $contactIds);

        return $contactIds;
    }

    private function updateBirthdate(array $contactIds): void
    {
        try {
            foreach ($contactIds as $contactId => $birthdate) {
                $this->b24->getCRMScope()->contact()->update($contactId, [
                    'BIRTHDATE' => $birthdate,
                    'UF_CRM_1657085677401' => $birthdate, // Еще какое-то день рождения
                ]);
            }
        } catch (BaseException|TransportException $e) {
            Logger::error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}