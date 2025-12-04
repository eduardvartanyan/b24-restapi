<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\B24Service;

readonly class ReviewController
{
    public function __construct(private B24Service $b24Service) {}

    public function showForm(string $dealRid, string $contactRid) : void
    {
        $questions = $this->b24Service->loadListItems(42); // 42 — список вопросов

        $this->render('review/form', [
            'dealRid'    => $dealRid,
            'contactRid' => $contactRid,
            'questions'  => $questions,
        ]);
    }

    public function submit(): void
    {
        $dealRid    = $_POST['dealIRid'] ?? null;
        $contactRid = $_POST['contactRid'] ?? null;
        $answers    = $_POST['rating'] ?? [];
        $comment    = $_POST['comment'] ?? null;
        $recommend  = $_POST['recommend'] === 'yes';

        print_r($answers);

        if (!$dealRid || !$contactRid || empty($answers)) {
            http_response_code(400);
            echo 'Некорректные данные';
            return;
        }

        $dealId = $this->b24Service->getDealIdByRid($dealRid);
        $contactId = $this->b24Service->getContactIdByRid($contactRid);

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
