<?php
declare(strict_types=1);

namespace App\Services;

use Bitrix24\SDK\Core\Exceptions\BaseException;
use Bitrix24\SDK\Core\Exceptions\TransportException;
use Bitrix24\SDK\Services\ServiceBuilder;

class B24Service
{
    private array $data;

    public function __construct(private readonly ServiceBuilder $b24) { }

    /**
     * @throws TransportException
     * @throws BaseException
     */
    public function importBirthdate(array $data): void
    {
        $this->data = $data;
        $contactIds = $this->getContactIds();
        $this->updateBirthdate($contactIds);
    }

    /**
     * @throws TransportException
     * @throws BaseException
     */
    private function getContactIds(): array
    {
        $contactIds = [];
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
        return $contactIds;
    }

    /**
     * @throws TransportException
     * @throws BaseException
     */
    private function updateBirthdate(array $contactIds): void
    {
        foreach ($contactIds as $contactId => $birthdate) {
            $this->b24->getCRMScope()->contact()->update($contactId, [
                'BIRTHDATE' => $birthdate,
                'UF_CRM_1657085677401' => $birthdate, // Еще какое-то день рождения
            ]);
        }
    }

}