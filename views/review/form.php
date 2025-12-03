<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оставить отзыв</title>
    <link rel="stylesheet" href="/css/review.css">
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>Ваш отзыв</h1>
        <p class="lead">Пожалуйста, оцените каждый пункт по шкале от 1 до 10.</p>
    </div>
    <form action="/review/submit" method="POST">
        <input type="hidden" name="dealId" value="<?= htmlspecialchars($dealId) ?>">
        <input type="hidden" name="contactId" value="<?= htmlspecialchars($contactId) ?>">
        <?php foreach ($questions as $question): ?>
            <div class="question-block">
                <div class="question-title">
                    <?= htmlspecialchars($question['NAME']) ?>
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
        <?php endforeach; ?>
        <button type="submit" class="primary-btn">Отправить отзыв</button>
    </form>
</div>
</body>
</html>
