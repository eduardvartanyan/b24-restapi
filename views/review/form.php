<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Оставить отзыв</title>
    <link rel="stylesheet" href="../css/review.css">
</head>
<body>
<div class="container">
    <h2>Ваш отзыв</h2>
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
                        <label>
                            <input type="radio"
                                   name="rating[<?= htmlspecialchars($question['ID']) ?>]"
                                   value="<?= $i ?>"
                                   required>
                            <?= $i ?>
                        </label>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <button type="submit">Отправить отзыв</button>
    </form>
</div>
</body>
</html>