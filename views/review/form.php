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
    <form action="/review/submit" method="POST">
        <input type="hidden" name="dealId" value="<?= htmlspecialchars($dealId) ?>">
        <input type="hidden" name="contactId" value="<?= htmlspecialchars($contactId) ?>">
        <?php foreach ($questions as $question): ?>
            <div class="question-block">
                <div class="question-title">
                    <?= $index . '. ' . htmlspecialchars($question['NAME']) ?>
                </div>
                <div class="rating">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <label class="rating-item rating-item-<?= $i ?>">
                            <input type="radio"
                                   name="rating[<?= htmlspecialchars($question['ID']) ?>]"
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
            <div class="question-title">
                <?= ($index) . '. ' ?>Если Ваш знакомый попал в ДТП, порекомендуете вызвать Форсайт?
            </div>
            <div class="yesno-block">
                <label class="yesno-option">
                    <input type="radio" name="recommend" value="yes" required>
                    <span>Да</span>
                </label>
                <label class="yesno-option">
                    <input type="radio" name="recommend" value="no" required>
                    <span>Нет</span>
                </label>
            </div>
        </div>
        <button type="submit" class="primary-btn">Отправить отзыв</button>
    </form>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const ratingInputs = document.querySelectorAll('input[type="radio"][name^="rating"]');
        const recommendBlock = document.getElementById('recommend-block');

        function updateRecommendVisibility() {
            let sum = 0;
            let count = 0;

            document.querySelectorAll('input[type="radio"][name^="rating"]:checked')
                .forEach(r => {
                    sum += parseInt(r.value);
                    count++;
                });

            if (count === 0) {
                recommendBlock.style.display = 'none';
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
        ratingInputs.forEach(r => r.addEventListener('change', updateRecommendVisibility));
    });
</script>
</body>
</html>
