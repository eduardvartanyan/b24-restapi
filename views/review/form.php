<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оставить отзыв</title>
    <link rel="stylesheet" href="/css/review.css">
</head>
<body>
<?php $index = 1; ?>
<div class="container">
    <div class="page-header">
        <h1>Ваш отзыв</h1>
        <p class="lead"></p>
    </div>
    <form action="/submit" method="POST">
        <input type="hidden" name="dealRid" value="<?= htmlspecialchars($dealRid) ?>">
        <input type="hidden" name="contactRid" value="<?= htmlspecialchars($contactRid) ?>">
        <?php foreach ($questions as $question): ?>
            <div class="question-block">
                <div class="question-title">
                    <?= $index . '. ' . htmlspecialchars($question['NAME']) ?>
                </div>
                <div class="rating">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <label class="rating-item rating-item-<?= $i ?>">
                            <input type="radio"
                                   name="rating[<?= htmlspecialchars($question['NAME']) ?>]"
                                   value="<?= $i ?>"
                                   required>
                            <span><?= $i ?></span>
                        </label>
                    <?php endfor; ?>
                </div>
            </div>
            <?php ++$index; ?>
        <?php endforeach; ?>
        <div class="question-block">
            <div class="question-title"><?= ($index) . '. ' ?>Что нам нужно сделать, чтобы стать лучше</div>
            <textarea
                name="comment"
                placeholder="Напишите свой отзыв здесь"
                class="review-textarea"
            ></textarea>
            <?php ++$index; ?>
        </div>
        <div class="question-block" id="recommend-block" style="display:none;">
            <?php $title = 'Оцените вероятность того, что вы порекомендуете Форсайт своим друзьям, если они попадут в ДТП'; ?>
            <div class="question-title">
                <?= ($index) . '. ' . $title ?>
            </div>
            <div class="rating recommend">
                <?php for ($i = 1; $i <= 10; $i++): ?>
                    <label class="rating-item rating-item-<?= $i ?>">
                        <input type="radio"
                               name="recommend"
                               value="<?= $i ?>"
                               required>
                        <span><?= $i ?></span>
                    </label>
                <?php endfor; ?>
            </div>
        </div>
        <button type="submit" class="primary-btn">Отправить отзыв</button>
    </form>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {

        const ratingGroups = document.querySelectorAll('.rating:not(.recommend)');
        const recommendBlock = document.getElementById('recommend-block');

        function updateRecommendVisibility() {

            let sum = 0;
            let count = ratingGroups.length;
            let answered = 0;

            ratingGroups.forEach(group => {
                const checked = group.querySelector('input[type="radio"]:checked');

                if (checked) {
                    answered++;
                    sum += parseInt(checked.value);
                } else {
                    sum += 0;
                }
            });

            if (answered < count) {
                recommendBlock.style.display = 'none';

                const selected = document.querySelector('input[name="recommend"]:checked');
                if (selected) selected.checked = false;

                return;
            }

            const avg = sum / count;

            if (avg >= 7) {
                recommendBlock.style.display = 'block';
            } else {
                recommendBlock.style.display = 'none';

                const selected = document.querySelector('input[name="recommend"]:checked');
                if (selected) selected.checked = false;
            }
        }

        document.querySelectorAll('input[type="radio"][name^="rating"]').forEach(r => {
            r.addEventListener('change', updateRecommendVisibility);
        });
    });
</script>
</body>
</html>
