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

    public function dealIsExist(array $fields): ?int
    {
        try {
            $deals = $this->b24->getCRMScope()->deal()->list([], $fields, ['ID']);
            $result = $deals->getCoreResponse()->getResponseData()->getResult();
            if (count($result) > 0) {
                return (int) $result[0]['ID'];
            }
        } catch (Throwable $e) {
            Logger::error('Ошибка при проверке сделки', [
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
            if ($item['name'] == '' || $item['phone'] == '' || $item['birthdate'] == '') { continue; }

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

    public function loadListItems(int $iblockId): array
    {
        try {
            $items = $this->b24->core->call('lists.element.get', [
                'IBLOCK_TYPE_ID' => 'lists',
                'IBLOCK_ID' => $iblockId,
            ]);

            return $items->getResponseData()->getResult();
        } catch (Throwable $e) {
            Logger::error('Ошибка при получении ID контакта', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => $iblockId
            ]);
        }

        return [];
    }

    public function getDealIdByRid(string $rid): ?int
    {
        try {
            $result = $this->b24->getCRMScope()->deal()->list(
                [],
                ['UF_CRM_1764653513' => $rid],
                ['ID'],
            );
            $deals = $result->getDeals();

            if (count($deals) < 0) return null;

            return $deals[0]->ID;
        } catch (Throwable $e) {
            Logger::error('Ошибка при получении ID контакта', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => $rid
            ]);
        }

        return null;
    }

    public function getContactIdByRid(string $rid): ?int
    {
        try {
            $result = $this->b24->getCRMScope()->contact()->list(
                [],
                ['UF_CRM_1764653531' => $rid],
                ['ID'],
                0
            );
            $contacts = $result->getContacts();

            if (count($contacts) < 0) return null;

            return $contacts[0]->ID;
        } catch (Throwable $e) {
            Logger::error('Ошибка при получении ID контакта', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => $rid
            ]);
        }

        return null;
    }

    public function addDynamicItem(int $entityTypeId, array $fields): ?int
    {
        try {
            $result = $this->b24->getCRMScope()->item()->add($entityTypeId, $fields);

            return $result->item()->id;
        } catch (Throwable $e) {
            Logger::error('Ошибка при получении ID контакта', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => [
                    'entityTypeId' => $entityTypeId,
                    'fields'       => $fields,
                ]
            ]);
        }

        return null;
    }

    public function getDynamicItem(int $entityTypeId, array $filter): ?array
    {
        $result = [];

        try {
            $items = $this->b24->core->call('crm.item.list', [
                'entityTypeId' => $entityTypeId,
                'select'       => ['*'],
                'filter'       => $filter,
            ]);

            $result = $items->getResponseData()->getResult()['items'];
        } catch (Throwable $e) {
            Logger::error('Ошибка при получении ID контакта', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => [
                    'entityTypeId' => $entityTypeId,
                    'filter'       => $filter,
                ]
            ]);
        }

        return $result;
    }

    public function sendCurl(string $method, array $params): array
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $_ENV['B24_WEBHOOK_CODE'] . $method . '.json?' . http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ));

        $response = curl_exec($curl);
        $result = json_decode($response, true);

        curl_close($curl);

        return $result ?? [];
    }

    public function updateDeal(int $dealId, string $field, string $value): void
    {
        try {
            $this->b24->getCRMScope()->deal()->update($dealId, [
                $field => $value
            ]);
        } catch (Throwable $e) {
            Logger::error('Ошибка при обновлении сделки', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => [
                    'dealId' => $dealId,
                    'field'  => $field,
                    'value'  => $value,
                ]
            ]);
        }
    }
}
