<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\B24Service;

readonly class ReviewController
{
    public function __construct(private B24Service $b24Service) {}

    public function showForm(string $dealRid, string $contactRid) : void
    {
        $dealId = $this->b24Service->getDealIdByRid($dealRid);
        $contactId = $this->b24Service->getContactIdByRid($contactRid);
        $questions = $this->b24Service->loadListItems(42); // 42 — список вопросов

        $dealId = 170686;
        $contactId = 247556;

        $this->render('review/form', [
            'dealId'    => $dealId,
            'contactId' => $contactId,
            'questions' => $questions,
        ]);
    }

    public function submit(): void
    {
        $dealId    = $_POST['dealId'] ?? null;
        $contactId = $_POST['contactId'] ?? null;
        $answers   = $_POST['rating'] ?? [];

        if (!$dealId || !$contactId || empty($answers)) {
            http_response_code(400);
            echo 'Некорректные данные';
            return;
        }

        $fields = [
            'TITLE' => '789',
        ];
        $this->b24Service->addDynamicItem(1032, $fields); // 1032 - Отзывы

        $this->render('review/success', []);
    }

    private function render(string $template, array $data = []): void
    {
        extract($data);
        require __DIR__ . "/../../../views/{$template}.php";
    }
}
