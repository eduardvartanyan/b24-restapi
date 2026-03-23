<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use Bitrix24\SDK\Services\ServiceBuilder;
use DateTimeImmutable;
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

    public function setMaxChatId(int $contactId, int $maxChatId): void
    {
        try {
            $this->b24->getCRMScope()->contact()->update($contactId, [
                'UF_CRM_1773132631' => $maxChatId,
            ]);
        } catch (Throwable $e) {
            Logger::error('Ошибка при заполнении Max Chat ID', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => [
                    'contactId' => $contactId,
                    'maxChatId' => $maxChatId,
                ]
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

    public function getDealsReportByContactId(int|string $contactId): string
    {
        $report = null;
        $types = [
            '14' => 'Вызов аварийного комиссара',
        ];
        $statuses = [
            'C14:NEW' => [
                'emoji' => '⏳ ',
                'text' => 'Обрабатываем заявку'
            ],
            'C14:PREPARATION' => [
                'emoji' => '📋 ',
                'text' => 'В работе у аварийного комиссара'
            ],
            'C14:UC_06USC6' => [
                'emoji' => '✅ ',
                'text' => 'ДТП оформлено'
            ],
            'C14:PREPAYMENT_INVOIC' => [
                'emoji' => '✍️ ',
                'text' => 'Оформляем документы для ГАИ'
            ],
            'C14:EXECUTING' => [
                'emoji' => '📨 ',
                'text' => 'Передаем документы в ГАИ'
            ],
            'C14:FINAL_INVOICE' => [
                'emoji' => '👀 ',
                'text' => 'Ожидаем документы из ГАИ'
            ],
            'C14:UC_RT6JEL' => [
                'emoji' => '👀 ',
                'text' => 'Ожидаем документы из ГАИ'
            ],
        ];

        try {
            foreach ($this->b24->getCRMScope()->deal()->list(
                [],
                [
                    'CONTACT_ID' => $contactId,
                    'CATEGORY_ID' => ['14'],
                    'STAGE_ID' => ['C14:NEW', 'C14:PREPARATION', 'C14:UC_06USC6', 'C14:PREPAYMENT_INVOIC',
                        'C14:EXECUTING', 'C14:FINAL_INVOICE', 'C14:UC_RT6JEL'],
                ],
                ['*'],
            )->getDeals() as $deal) {
                $report .= $statuses[$deal->STAGE_ID]['emoji'] . 'Заявка № ' . $deal->ID . ' от '
                    . $deal->DATE_CREATE->format('d.m.Y') . ' — ' . $types[$deal->CATEGORY_ID] . PHP_EOL;
                $report .= 'Статус: ' . $statuses[$deal->STAGE_ID]['text'] . PHP_EOL;
                $report .= PHP_EOL;
            }
        } catch (Throwable $e) {
            Logger::error('[B24Service->getDealsStatusesByContactId]', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => [
                    'contactId' => $contactId,
                ]
            ]);
        }

        if (!$report) {
            $report = 'Активных заявок не найдено';
        }

        Logger::info('[B24Service->getDealsReportByContactId]', [
            'contactId' => $contactId,
            'report'    => $report,
        ]);

        return $report;
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

    public function getContactIdByMaxChatId(int $maxChatId): ?int
    {
        try {
            $result = $this->b24->getCRMScope()->contact()->list(
                [],
                ['UF_CRM_1773132631' => $maxChatId],
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
                'data'    => $maxChatId
            ]);
        }

        return null;
    }

    public function getContactIdByPhone(string $phone): ?int
    {
        try {
            $result = $this->b24->getCRMScope()->contact()->list(
                [],
                ['PHONE' => $phone],
                ['ID'],
                0
            );
            $contacts = $result->getContacts();

            return $contacts[0]->ID;
        } catch (Throwable $e) {
            Logger::error('Ошибка при получении ID контакта', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => $phone
            ]);
        }

        return null;
    }

    public function addContact(array $fields): ?int
    {
        try {
            $result = $this->b24->getCRMScope()->contact()->add($fields);

            return $result->getId();
        } catch (Throwable $e) {
            Logger::error('Ошибка при добавлении контакта', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => $fields,
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

    public function addTask(array $fields): ?int
    {
        try {
            $result = $this->b24->core->call(
                'tasks.task.add', ['fields' => $fields]
            )->getResponseData()->getResult();
            return (int) $result['task']['id'];
        } catch (Throwable $e) {
            Logger::error('[B24Service->addTask] Ошибка при добавлении задачи', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'data'    => $fields,
            ]);
        }

        return null;
    }

    public function getDtpConsultantId($contactId): ?int
    {
        try {
            $assignedById = null;
            foreach ($this->b24->getCRMScope()->deal()->list(
                [],
                [
                    'CONTACT_ID' => $contactId,
                    'CATEGORY_ID' => ['14'],
                    'STAGE_ID' => ['C14:UC_06USC6', 'C14:PREPAYMENT_INVOIC', 'C14:EXECUTING', 'C14:FINAL_INVOICE',
                        'C14:UC_RT6JEL'],
                ],
                ['*'],
            )->getDeals() as $deal) {
                return $deal->ASSIGNED_BY_ID;
            }
            if (!$assignedById) {
                $maxUser = $maxDate = null;
                foreach ($this->b24->core->call(
                    'im.department.employees.get',
                    [
                        'ID' => [37],
                        'USER_DATA' => 'Y',
                    ]
                )->getResponseData()->getResult() as $item) {
                    foreach ($item as $user) {
                        if (empty($user['last_activity_date'])) { continue; }
                        $currentDate = new DateTimeImmutable($user['last_activity_date']);
                        if ($maxDate === null || $currentDate > $maxDate) {
                            $maxDate = $currentDate;
                            $maxUser = $user;
                        }
                    }
                }
                return (int) $maxUser['id'] ?? 43;
            }
        } catch (Throwable $e) {
            Logger::error('[B24Service->getDispatcherOnline] Ошибка при получении диспетчера онлайн', [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
            ]);
        }
        return null;
    }
}
