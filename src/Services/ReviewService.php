<?php
declare(strict_types=1);

namespace App\Services;

readonly class ReviewService
{
    private const int REVIEW_ENTITY_TYPE_ID = 1032;
    private const int SCORE_ENTITY_TYPE_ID = 1036;

    public function __construct(private B24Service $b24Service) {}

    public function saveReview(
        int    $dealId,
        int    $contactId,
        array  $answers,
        string $comment,
        int    $recommend
    ): void {
        $fields = [
            'CONTACT_ID'        => $contactId,
            'PARENT_ID_2'       => $dealId,
            'ufCrm8_1764745827' => $this->calculateAvgRating($answers),
            'ufCrm8_1764745855' => $comment,
            'ufCrm8_1770349621' => $recommend,
        ];
        $reviewId = $this->b24Service->addDynamicItem(self::REVIEW_ENTITY_TYPE_ID, $fields);

        if ($reviewId) {
            foreach ($answers as $answer => $score) {
                $fields = [
                    'TITLE'              => $answer,
                    'PARENT_ID_1032'     => $reviewId,
                    'ufCrm10_1764845073' => (int) $score,
                ];
                $this->b24Service->addDynamicItem(self::SCORE_ENTITY_TYPE_ID, $fields);
            }
        }
    }

    public function checkReview(int $dealId, int $contactId): bool
    {
        $response = $this->b24Service->sendCurl('crm.item.list', [
            'entityTypeId'        => self::REVIEW_ENTITY_TYPE_ID,
            'filter[CONTACT_ID]'  => $contactId,
            'filter[PARENT_ID_2]' => $dealId,
        ]);

        return $response['total'] && $response['total'] > 0;
    }

    private function calculateAvgRating(array $answers): float
    {
        return array_sum($answers) / count($answers);
    }
}