<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('Student access only.');
}

$pdo = db();
$userId = (int)$user['id'];

$rawModule = $_GET['module_id'] ?? null;
$moduleId = filter_var($rawModule, FILTER_VALIDATE_INT);
$moduleId = ($moduleId && $moduleId > 0) ? $moduleId : null;

$theme = (string)($user['theme'] ?? 'dark');
$error = '';
$profile = null;
$moduleLabel = '';
$attempts = [];
$topics = [];
$modules = [];
$totalAnswers = 0;
$correctAnswers = 0;

try {
    $stmt = $pdo->prepare("
        SELECT p.degree_id, p.batch_id,
               d.name AS degree_name, b.batch_code
        FROM student_academic_profiles p
        JOIN academic_degrees d ON d.id = p.degree_id
        JOIN academic_batches b ON b.id = p.batch_id
        WHERE p.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        throw new RuntimeException(
            'Please complete your academic profile first.'
        );
    }

    if ($moduleId !== null) {
        $stmt = $pdo->prepare("
            SELECT m.module_code, m.module_name
            FROM academic_curriculum c
            JOIN academic_modules m ON m.id = c.module_id
            WHERE c.degree_id = ?
              AND c.batch_id = ?
              AND c.module_id = ?
              AND c.is_active = 1
              AND m.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([
            $profile['degree_id'],
            $profile['batch_id'],
            $moduleId
        ]);

        $selected = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$selected) {
            http_response_code(403);
            throw new RuntimeException(
                'This module is not assigned to your profile.'
            );
        }

        $moduleLabel = $selected['module_code']
            . ' · ' . $selected['module_name'];
    }

    $scope = "
        FROM quiz_attempts a
        JOIN quizzes q ON q.id = a.quiz_id
        JOIN academic_modules m ON m.id = q.module_id
        JOIN academic_curriculum c
          ON c.module_id = m.id
        JOIN student_academic_profiles p
          ON p.user_id = a.user_id
         AND p.degree_id = c.degree_id
         AND p.batch_id = c.batch_id
        WHERE a.user_id = ?
          AND c.is_active = 1
          AND m.is_active = 1
          AND a.status IN ('submitted', 'timed_out')
          AND a.submitted_at IS NOT NULL
          AND (? IS NULL OR q.module_id = ?)
    ";

    $params = [$userId, $moduleId, $moduleId];

    $stmt = $pdo->prepare("
        SELECT a.id, a.score, a.max_score,
               a.submitted_at, q.title,
               m.module_code, m.module_name,
               CASE WHEN a.max_score > 0
                 THEN a.score * 100 / a.max_score
                 ELSE 0 END AS percentage
        $scope
        ORDER BY a.submitted_at DESC, a.id DESC
    ");
    $stmt->execute($params);
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT m.module_code, m.module_name,
               COUNT(a.id) AS attempt_count,
               AVG(CASE WHEN a.max_score > 0
                 THEN a.score * 100 / a.max_score
                 ELSE 0 END) AS average_score
        $scope
        GROUP BY m.id, m.module_code, m.module_name
        ORDER BY m.module_name
    ");
    $stmt->execute($params);
    $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $answerScope = "
        FROM quiz_attempt_answers aa
        JOIN quiz_attempts a ON a.id = aa.attempt_id
        JOIN quiz_questions qq ON qq.id = aa.question_id
        JOIN quizzes q ON q.id = a.quiz_id
        JOIN academic_modules m ON m.id = q.module_id
        JOIN academic_curriculum c
          ON c.module_id = m.id
        JOIN student_academic_profiles p
          ON p.user_id = a.user_id
         AND p.degree_id = c.degree_id
         AND p.batch_id = c.batch_id
        WHERE a.user_id = ?
          AND c.is_active = 1
          AND m.is_active = 1
          AND a.status IN ('submitted', 'timed_out')
          AND a.submitted_at IS NOT NULL
          AND qq.quiz_id = q.id
          AND (? IS NULL OR q.module_id = ?)
    ";

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(
                 CASE WHEN aa.is_correct = 1
                 THEN 1 ELSE 0 END
               ), 0) AS correct
        $answerScope
    ");
    $stmt->execute($params);
    $answerTotals = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalAnswers = (int)$answerTotals['total'];
    $correctAnswers = (int)$answerTotals['correct'];

    $stmt = $pdo->prepare("
        SELECT COALESCE(
                 NULLIF(TRIM(qq.topic), ''),
                 'General'
               ) AS topic,
               COUNT(*) AS total,
               SUM(CASE WHEN aa.is_correct = 1
                   THEN 1 ELSE 0 END) AS correct,
               ROUND(100 * SUM(
                 CASE WHEN aa.is_correct = 1
                 THEN 1 ELSE 0 END
               ) / COUNT(*), 1) AS accuracy
        $answerScope
        GROUP BY COALESCE(
            NULLIF(TRIM(qq.topic), ''), 'General'
        )
        ORDER BY accuracy ASC, total DESC, topic ASC
        LIMIT 6
    ");
    $stmt->execute($params);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $exception) {
    error_log('ULearn analytics: ' . $exception->getMessage());

    $knownErrors = [
        'Please complete your academic profile first.',
        'This module is not assigned to your profile.'
    ];

    $error = in_array(
        $exception->getMessage(),
        $knownErrors,
        true
    ) ? $exception->getMessage()
      : 'Unable to load analytics. Please contact the administrator.';

    $attempts = [];
    $topics = [];
    $modules = [];
}

$count = count($attempts);
$percentages = array_map(
    static fn($a) => (float)$a['percentage'],
    $attempts
);

$average = $count ? array_sum($percentages) / $count : 0;
$best = $count ? max($percentages) : 0;
$accuracy = $totalAnswers
    ? $correctAnswers * 100 / $totalAnswers : 0;

$firstName = explode(
    ' ',
    trim((string)($user['full_name'] ?? 'Student'))
)[0];

$recent = array_slice($attempts, 0, 5);
$trend = array_reverse(array_slice($attempts, 0, 8));

$topicUrl = url('dashboard/topic-analysis.php')
    . ($moduleId !== null ? '?module_id=' . $moduleId : '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Performance Analytics | ULearn</title>

<link rel="stylesheet" href="../assets/css/style.css">

<script>
(function () {
    const theme = <?= json_encode($theme) ?>;
    const chosen = theme === 'light' || theme === 'dark'
        ? theme
        : (matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark' : 'light');
    document.documentElement.dataset.theme = chosen;
})();
</script>

<style>
/* ULearn Analytics v2 — isolated design system */

.ula-page {
    --ula-radius: 16px;
    max-width: 1200px;
    margin: 0 auto;
    padding: 28px 28px 70px;
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
}

.ula-page * {
    box-sizing: border-box;
}

.ula-page h1,
.ula-page h2,
.ula-page p {
    margin: 0;
}

.ula-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    padding-bottom: 25px;
    border-bottom: 1px solid var(--line);
}

.ula-logo {
    font-family: 'Manrope', sans-serif;
    font-size: 23px;
    font-weight: 850;
    letter-spacing: -1.3px;
}

.ula-logo span,
.ula-accent {
    color: var(--accent);
}

.ula-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.ula-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 15px;
    border: 1px solid var(--line);
    border-radius: 10px;
    color: var(--text);
    background: var(--surface);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    transition: background .2s, border-color .2s,
                transform .2s;
}

.ula-btn:hover {
    background: var(--soft);
    border-color: var(--accent);
    transform: translateY(-2px);
}

.ula-btn-primary {
    background: var(--accent);
    color: #10121a;
    border-color: var(--accent);
}

.ula-btn-primary:hover {
    background: var(--accent);
    color: #10121a;
    opacity: .9;
}

.ula-hero {
    display: block !important;
    min-height: 0 !important;
    height: auto !important;
    grid-template-columns: none !important;
    padding: 42px 0 30px !important;
    margin: 0 !important;
    background: transparent;
    border: 0;
}

.ula-eyebrow {
    color: var(--accent);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.6px;
    text-transform: uppercase;
}

.ula-hero h1 {
    font-family: 'Manrope', sans-serif;
    font-size: clamp(30px, 4vw, 43px);
    font-weight: 850;
    letter-spacing: -1.8px;
    line-height: 1.2;
    margin: 12px 0 12px;
}

.ula-description {
    max-width: 650px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.8;
}

.ula-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 20px;
}

.ula-tag {
    background: var(--soft);
    color: var(--text);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 7px 11px;
    font-size: 11px;
    font-weight: 700;
}

.ula-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin: 0 0 18px;
}

.ula-stat,
.ula-panel {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--ula-radius);
    transition: border-color .25s, transform .25s,
                box-shadow .25s;
}

.ula-stat:hover,
.ula-panel:hover {
    border-color: color-mix(
        in srgb, var(--accent) 35%, var(--line)
    );
}

.ula-stat:hover {
    transform: translateY(-3px);
}

.ula-stat {
    padding: 22px;
    min-width: 0;
}

.ula-stat-icon {
    display: inline-grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 9px;
    background: var(--soft);
    color: var(--accent);
    font-size: 15px;
    margin-bottom: 17px;
}

.ula-stat-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 650;
}

.ula-stat-number {
    font-family: 'Manrope', sans-serif;
    font-size: clamp(25px, 3vw, 35px);
    font-weight: 850;
    letter-spacing: -1.5px;
    margin: 8px 0;
    font-variant-numeric: tabular-nums;
}

.ula-stat-foot {
    color: var(--muted);
    font-size: 11px;
    line-height: 1.5;
}

.ula-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 18px;
}

.ula-panel {
    padding: 25px;
    min-width: 0;
}

.ula-panel h2 {
    font-family: 'Manrope', sans-serif;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -.5px;
    margin: 8px 0;
}

.ula-panel-desc {
    font-size: 12px;
    color: var(--muted);
    line-height: 1.7;
    margin-bottom: 20px !important;
}

.ula-row {
    padding: 15px 0;
    border-bottom: 1px solid var(--line);
}

.ula-row:last-child {
    border-bottom: 0;
}

.ula-row-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.ula-row-title {
    font-size: 12px;
    font-weight: 750;
    overflow-wrap: anywhere;
}

.ula-row-value {
    font-size: 14px;
    font-weight: 800;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.ula-small {
    color: var(--muted);
    font-size: 11px;
    margin-top: 6px;
    line-height: 1.6;
}

.ula-progress {
    height: 7px;
    background: var(--soft);
    border-radius: 99px;
    overflow: hidden;
    margin-top: 12px;
}

.ula-progress-fill {
    height: 100%;
    background: var(--accent);
    border-radius: 99px;
    transform-origin: left;
    animation: ula-grow .85s ease-out both;
}

.ula-chart {
    display: flex;
    align-items: flex-end;
    justify-content: space-around;
    gap: 12px;
    height: 195px;
    border-bottom: 1px solid var(--line);
    padding: 12px 8px 0;
}

.ula-chart-column {
    flex: 1;
    height: 100%;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
}

.ula-chart-value {
    font-size: 11px;
    font-weight: 750;
    color: var(--muted);
    margin-bottom: 8px;
}

.ula-chart-bar {
    width: min(40px, 75%);
    background: var(--accent);
    border-radius: 6px 6px 0 0;
    transform-origin: bottom;
    animation: ula-rise .8s ease-out both;
}

.ula-empty {
    padding: 22px;
    border: 1px dashed var(--line);
    border-radius: 12px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.8;
}

.ula-error {
    margin-top: 18px;
    padding: 15px;
    border: 1px solid #b86b6b;
    border-radius: 12px;
    font-size: 12px;
}

.ula-footer {
    text-align: center;
    color: var(--muted);
    font-size: 11px;
    margin-top: 35px;
}

.ula-enter {
    animation: ula-enter .5s ease-out both;
}

.ula-stats {
    animation-delay: .08s;
}

.ula-grid {
    animation-delay: .13s;
}

@keyframes ula-enter {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes ula-grow {
    from { transform: scaleX(0); }
    to { transform: scaleX(1); }
}

@keyframes ula-rise {
    from { transform: scaleY(0); }
    to { transform: scaleY(1); }
}

@media (max-width: 850px) {
    .ula-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .ula-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 520px) {
    .ula-page {
        padding: 18px 14px 45px;
    }
    .ula-hero {
        padding: 30px 0 24px !important;
    }
    .ula-stat,
    .ula-panel {
        padding: 17px;
    }
    .ula-stats {
        gap: 10px;
    }
    .ula-stat-number {
        font-size: 26px;
    }
    .ula-stat-icon {
        margin-bottom: 12px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .ula-page *,
    .ula-page *::before,
    .ula-page *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }
}
</style>
</head>

<body>

<main class="ula-page">

<header class="ula-top">
    <a class="ula-logo"
       href="<?= e(url('dashboard/index.php')) ?>">
        u learn<span>.</span>
    </a>

    <nav class="ula-actions">
        <a class="ula-btn" href="<?= e($topicUrl) ?>">
            Topic Weaknesses ↗
        </a>

        <a class="ula-btn"
           href="<?= e(url('dashboard/index.php')) ?>">
            ← Dashboard
        </a>
    </nav>
</header>

<?php if ($error !== ''): ?>
    <div class="ula-error"><?= e($error) ?></div>
<?php endif; ?>

<section class="ula-hero ula-enter">
    <div class="ula-eyebrow">
        Your learning / Measured
    </div>

    <h1>
        Performance
        <span class="ula-accent">Analytics.</span>
    </h1>

    <p class="ula-description">
        Welcome back, <?= e($firstName) ?>.
        Understand your quiz performance, discover
        your weak areas, and track your learning
        progress over time.
    </p>

    <div class="ula-tags">
        <?php if ($moduleLabel !== ''): ?>
            <span class="ula-tag">
                <?= e($moduleLabel) ?>
            </span>
        <?php endif; ?>

        <?php if ($profile): ?>
            <span class="ula-tag">
                <?= e((string)$profile['degree_name']) ?>
            </span>

            <span class="ula-tag">
                Batch <?= e((string)$profile['batch_code']) ?>
            </span>
        <?php endif; ?>
    </div>
</section>

<section class="ula-stats ula-enter">

    <article class="ula-stat">
        <div class="ula-stat-icon">✓</div>
        <div class="ula-stat-label">Completed quizzes</div>
        <div class="ula-stat-number"><?= $count ?></div>
        <div class="ula-stat-foot">Recorded attempts</div>
    </article>

    <article class="ula-stat">
        <div class="ula-stat-icon">↗</div>
        <div class="ula-stat-label">Average score</div>
        <div class="ula-stat-number">
            <?= $count ? number_format($average, 0) . '%' : '—' ?>
        </div>
        <div class="ula-stat-foot">Across completed quizzes</div>
    </article>

    <article class="ula-stat">
        <div class="ula-stat-icon">★</div>
        <div class="ula-stat-label">Personal best</div>
        <div class="ula-stat-number">
            <?= $count ? number_format($best, 0) . '%' : '—' ?>
        </div>
        <div class="ula-stat-foot">Highest recorded score</div>
    </article>

    <article class="ula-stat">
        <div class="ula-stat-icon">◎</div>
        <div class="ula-stat-label">Answer accuracy</div>
        <div class="ula-stat-number">
            <?= $totalAnswers
                ? number_format($accuracy, 0) . '%'
                : '—' ?>
        </div>
        <div class="ula-stat-foot">
            <?= $correctAnswers ?> correct /
            <?= $totalAnswers ?> recorded
        </div>
    </article>

</section>

<section class="ula-grid ula-enter">

    <article class="ula-panel">
        <div class="ula-eyebrow">Score history</div>
        <h2>Your progress</h2>
        <p class="ula-panel-desc">
            Performance across your latest quiz attempts.
        </p>

        <?php if (!$trend): ?>
            <div class="ula-empty">
                Complete a quiz to start tracking progress.
            </div>
        <?php else: ?>
            <div class="ula-chart">
                <?php foreach ($trend as $i => $attempt):
                    $score = max(
                        0,
                        min(100, (float)$attempt['percentage'])
                    );
                ?>
                    <div class="ula-chart-column"
                         title="<?= e((string)$attempt['title']) ?>">
                        <div class="ula-chart-value">
                            <?= number_format($score, 0) ?>%
                        </div>
                        <div class="ula-chart-bar"
                             style="height:<?= $score ?>%;
                                    animation-delay:<?= $i * 0.08 ?>s">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="ula-small">
                Older attempts → Most recent
            </p>
        <?php endif; ?>
    </article>

    <article class="ula-panel">
        <div class="ula-eyebrow">Learning insights</div>
        <h2>Topics to practise</h2>
        <p class="ula-panel-desc">
            Your lowest-accuracy topics appear first.
        </p>

        <?php if (!$topics): ?>
            <div class="ula-empty">
                Topic insights appear after quiz submissions.
            </div>
        <?php else: ?>
            <?php foreach ($topics as $topic):
                $value = max(
                    0,
                    min(100, (float)$topic['accuracy'])
                );
            ?>
                <div class="ula-row">
                    <div class="ula-row-head">
                        <span class="ula-row-title">
                            <?= e((string)$topic['topic']) ?>
                        </span>
                        <span class="ula-row-value">
                            <?= number_format($value, 0) ?>%
                        </span>
                    </div>

                    <div class="ula-progress">
                        <div class="ula-progress-fill"
                             style="width:<?= $value ?>%">
                        </div>
                    </div>

                    <div class="ula-small">
                        <?= (int)$topic['correct'] ?>
                        correct of
                        <?= (int)$topic['total'] ?>
                        recorded answers
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="ula-actions" style="margin-top:20px">
            <a class="ula-btn" href="<?= e($topicUrl) ?>">
                Explore topic weaknesses ↗
            </a>
        </div>
    </article>

</section>

<section class="ula-grid ula-enter">

    <article class="ula-panel">
        <div class="ula-eyebrow">Academic performance</div>
        <h2>Module breakdown</h2>
        <p class="ula-panel-desc">
            Performance calculated from saved quiz results.
        </p>

        <?php if (!$modules): ?>
            <div class="ula-empty">
                No module results available yet.
            </div>
        <?php else: ?>
            <?php foreach ($modules as $module): ?>
                <div class="ula-row">
                    <div class="ula-row-head">
                        <div>
                            <div class="ula-row-title">
                                <?= e((string)$module['module_code']) ?>
                                ·
                                <?= e((string)$module['module_name']) ?>
                            </div>

                            <div class="ula-small">
                                <?= (int)$module['attempt_count'] ?>
                                attempts
                            </div>
                        </div>

                        <div class="ula-row-value">
                            <?= number_format(
                                (float)$module['average_score'], 0
                            ) ?>%
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </article>

    <article class="ula-panel">
        <div class="ula-eyebrow">Recent activity</div>
        <h2>Latest quiz results</h2>
        <p class="ula-panel-desc">
            Your most recently completed quizzes.
        </p>

        <?php if (!$recent): ?>
            <div class="ula-empty">
                Your quiz results will appear here.
            </div>
        <?php else: ?>
            <?php foreach ($recent as $attempt): ?>
                <div class="ula-row">
                    <div class="ula-row-head">
                        <div>
                            <div class="ula-row-title">
                                <?= e((string)$attempt['title']) ?>
                            </div>

                            <div class="ula-small">
                                <?= e((string)$attempt['module_code']) ?>
                                ·
                                <?= e(date(
                                    'd M Y',
                                    strtotime(
                                        (string)$attempt['submitted_at']
                                    )
                                )) ?>
                            </div>
                        </div>

                        <div style="text-align:right">
                            <div class="ula-row-value">
                                <?= number_format(
                                    (float)$attempt['percentage'], 0
                                ) ?>%
                            </div>

                            <div class="ula-small">
                                <?= number_format(
                                    (float)$attempt['score'], 0
                                ) ?>
                                /
                                <?= number_format(
                                    (float)$attempt['max_score'], 0
                                ) ?>
                                marks
                            </div>

                            <a class="ula-small"
                               style="display:inline-block;color:var(--accent)"
                               href="<?= e(url(
                                   'dashboard/quizzes.php?result='
                                   . (int)$attempt['id']
                               )) ?>">
                                Review answers ↗
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="ula-actions" style="margin-top:20px">
            <a class="ula-btn ula-btn-primary"
               href="<?= e(url('dashboard/quizzes.php')) ?>">
                Go to Quiz Arena ↗
            </a>
        </div>
    </article>

</section>

<footer class="ula-footer">
    ULearn · Learn smarter, improve continuously.
</footer>

</main>
</body>
</html>
