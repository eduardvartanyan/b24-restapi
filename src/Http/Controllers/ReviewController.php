<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\B24Service;
use App\Services\ReviewService;

readonly class ReviewController
{
    public function __construct(
        private B24Service $b24Service,
        private ReviewService $reviewService,
    ) {}

    public function showForm(string $dealRid, string $contactRid) : void
    {
        $questions = $this->b24Service->loadListItems(42); // 42 — список вопросов

        $dealId = $this->b24Service->getDealIdByRid($dealRid);
        $contactId = $this->b24Service->getContactIdByRid($contactRid);

        if (!$dealId || !$contactId) {
            $this->render('review/error', []);
            return;
        }

        if ($this->reviewService->checkReview($dealId, $contactId)) {
            $this->render('review/success', []);
            return;
        }

        $this->render('review/form', [
            'dealRid'    => $dealRid,
            'contactRid' => $contactRid,
            'questions'  => $questions,
        ]);
    }

    public function submit(): void
    {
        $dealRid    = $_POST['dealRid'] ?? null;
        $contactRid = $_POST['contactRid'] ?? null;
        $answers    = $_POST['rating'] ?? [];
        $comment    = $_POST['comment'] ?? null;
        $recommend  = $_POST['recommend'] === 'yes';

        if (!$dealRid || !$contactRid || empty($answers)) {
            http_response_code(400);
            echo 'Некорректные данные';
            return;
        }

        $this->reviewService->saveReview(
            $this->b24Service->getDealIdByRid($dealRid),
            $this->b24Service->getContactIdByRid($contactRid),
            $answers,
            $comment,
            $recommend
        );

        $this->render('review/success', []);
    }

    private function render(string $template, array $data = []): void
    {
        extract($data);
        require __DIR__ . "/../../../views/{$template}.php";
    }
}
