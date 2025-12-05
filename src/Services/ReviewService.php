<?php
declare(strict_types=1);

namespace App\Services;

readonly class ReviewService
{
    public function __construct(private B24Service $b24Service) {}

    public function saveReview(
        string $dealRid,
        string $contactRid,
        array  $answers,
        string $comment,
        bool   $recommend
    ): void {
        $fields = [
            'CONTACT_ID'        => $this->b24Service->getContactIdByRid($contactRid),
            'PARENT_ID_2'       => $this->b24Service->getDealIdByRid($dealRid),
            'ufCrm8_1764745827' => $this->calculateAvgRating($answers),
            'ufCrm8_1764745855' => $comment,
            'ufCrm8_1764745902' => $recommend ? 'Y' : 'N',
        ];
        $reviewId = $this->b24Service->addDynamicItem(1032, $fields); // 1032 - Отзывы

        if ($reviewId) {
            foreach ($answers as $answer => $score) {
                $fields = [
                    'TITLE'              => $answer,
                    'PARENT_ID_1032'     => $reviewId,
                    'ufCrm10_1764845073' => (int) $score,
                ];
                $this->b24Service->addDynamicItem(1036, $fields); // 1036 - Оценки для отзывов
            }
        }
    }

    private function calculateAvgRating(array $answers): float
    {
        return array_sum($answers) / count($answers);
    }
}