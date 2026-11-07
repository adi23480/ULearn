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

$moduleId = ($moduleId !== false && $moduleId !== null && $moduleId > 0)

    ? $moduleId : null;

$theme = (string)($user['theme'] ?? 'dark');

$profile = null;

$moduleName = '';

$topics = [];

$mistakes = [];

$error = '';

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

    if ($moduleId !== null) {
        // A student may analyse only a module with their own completed quiz history.
        $stmt = $pdo->prepare("
            SELECT m.module_code, m.module_name
            FROM quiz_attempts a
            JOIN quizzes q ON q.id = a.quiz_id
            JOIN academic_modules m ON m.id = q.module_id
            WHERE a.user_id = ? AND q.module_id = ?
              AND a.status IN ('submitted', 'timed_out')
            LIMIT 1
        ");
        $stmt->execute([$userId, $moduleId]);
        $historicalModule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$historicalModule) {
            http_response_code(404);
            throw new RuntimeException('No completed quiz history was found for this module.');
        }
        $moduleName = $historicalModule['module_code'] . ' · ' . $historicalModule['module_name'];
    }

    $scope = "
        FROM quiz_attempt_answers aa
        JOIN quiz_attempts a ON a.id = aa.attempt_id
        JOIN quizzes q ON q.id = a.quiz_id
        JOIN quiz_questions qq ON qq.id = aa.question_id AND qq.quiz_id = q.id
        JOIN academic_modules m ON m.id = q.module_id
        WHERE a.user_id = ?
          AND a.status IN ('submitted', 'timed_out')
          AND (? IS NULL OR q.module_id = ?)
    ";

    $params = [$userId, $moduleId, $moduleId];

    // Aggregate saved answers by question topic for this student and module.
    $topicSql = "
        SELECT
          COALESCE(NULLIF(TRIM(qq.topic), ''), 'General') AS topic,
          COUNT(*) AS recorded,
          SUM(CASE WHEN aa.option_id IS NOT NULL THEN 1 ELSE 0 END) AS answered,
          SUM(CASE WHEN aa.option_id IS NOT NULL AND aa.is_correct = 1
              THEN 1 ELSE 0 END) AS correct,
          SUM(CASE WHEN aa.option_id IS NOT NULL AND aa.is_correct = 0
              THEN 1 ELSE 0 END) AS incorrect,
          ROUND(100.0 * SUM(CASE WHEN aa.option_id IS NOT NULL
              AND aa.is_correct = 1 THEN 1 ELSE 0 END)
              / NULLIF(SUM(CASE WHEN aa.option_id IS NOT NULL
              THEN 1 ELSE 0 END), 0), 1) AS accuracy
        $scope
          AND aa.option_id IS NOT NULL
        GROUP BY COALESCE(NULLIF(TRIM(qq.topic), ''), 'General')
        ORDER BY accuracy ASC, answered DESC, topic ASC
    ";
    $stmt = $pdo->prepare($topicSql);
    $stmt->execute($params);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // The mistake-only condition must be part of the WHERE clause.

    // Build a separate, explicit query to avoid ambiguous joins.

    $mistakeSql = "

        SELECT

          COALESCE(NULLIF(TRIM(qq.topic), ''), 'General')

            AS topic,

          qq.question_text,

          qq.explanation,

          q.title AS quiz_title,

          a.submitted_at,

          selected.option_text AS selected_answer,

          (

            SELECT qo.option_text

            FROM quiz_options qo

            WHERE qo.question_id = qq.id

              AND qo.is_correct = 1

            ORDER BY qo.id LIMIT 1

          ) AS correct_answer

        FROM quiz_attempt_answers aa

        JOIN quiz_attempts a ON a.id = aa.attempt_id

        JOIN quizzes q ON q.id = a.quiz_id

        JOIN quiz_questions qq

          ON qq.id = aa.question_id

         AND qq.quiz_id = q.id

        JOIN academic_modules m ON m.id = q.module_id


        LEFT JOIN quiz_options selected

          ON selected.id = aa.option_id

         AND selected.question_id = qq.id

        WHERE a.user_id = ?

          AND a.status IN ('submitted', 'timed_out')


          AND aa.option_id IS NOT NULL

          AND aa.is_correct = 0

          AND (? IS NULL OR q.module_id = ?)

        ORDER BY a.submitted_at DESC, aa.id DESC

        LIMIT 8

    ";

    $stmt = $pdo->prepare($mistakeSql);

    $stmt->execute($params);

    $mistakes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $exception) {

    error_log('ULearn topic analysis: ' . $exception->getMessage());

    $known = [

        'Please complete your academic profile first.',

        'No completed quiz history was found for this module.'

    ];

    $error = in_array($exception->getMessage(), $known, true)

        ? $exception->getMessage()

        : 'Unable to load topic analysis. Please contact the administrator.';

    $topics = [];

    $mistakes = [];

}

$total = array_sum(array_map(

    static fn($t) => (int)$t['answered'], $topics

));

$correct = array_sum(array_map(

    static fn($t) => (int)$t['correct'], $topics

));

$accuracy = $total > 0 ? $correct * 100 / $total : 0;

$firstName = explode(

    ' ', trim((string)($user['full_name'] ?? 'Student'))

)[0];

$focus = $topics[0] ?? null;

$priorities = array_slice($topics, 0, 4);

$analyticsUrl = url('dashboard/analytics.php')

    . ($moduleId !== null ? '?module_id=' . $moduleId : '');

function topicPriority(array $topic): string {

    $count = (int)$topic['answered'];

    $accuracy = (float)$topic['accuracy'];

    if ($count < 5) return 'BUILD DATA';

    if ($accuracy < 50) return 'HIGH PRIORITY';

    if ($accuracy < 75) return 'PRACTISE';

    return 'MAINTAIN';

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Topic Weaknesses | ULearn</title>

<link rel="stylesheet" href="../assets/css/style.css">

<script>

(function(){

 const saved = <?= json_encode($theme) ?>;

 document.documentElement.dataset.theme =

   saved === 'dark' || saved === 'light'

     ? saved

     : (matchMedia('(prefers-color-scheme:dark)').matches

         ? 'dark' : 'light');

})();

</script>

<style>

.ult-page {

  max-width:1200px;

  margin:0 auto;

  padding:28px 28px 70px;

  color:var(--text);

  font-family:'DM Sans',sans-serif;

}

.ult-page * {box-sizing:border-box}

.ult-page h1,.ult-page h2,.ult-page h3,.ult-page p {margin:0}

.ult-top {

  display:flex;align-items:center;justify-content:space-between;

  flex-wrap:wrap;gap:16px;padding-bottom:25px;

  border-bottom:1px solid var(--line);

}

.ult-logo {

  font:850 23px 'Manrope',sans-serif;

  letter-spacing:-1.3px;text-decoration:none;color:var(--text);

}

.ult-logo span,.ult-purple {color:var(--accent)}

.ult-actions {display:flex;flex-wrap:wrap;gap:10px}

.ult-btn {

  display:inline-flex;align-items:center;justify-content:center;

  padding:11px 15px;border:1px solid var(--line);

  border-radius:10px;background:var(--surface);

  color:var(--text);font-size:12px;font-weight:700;

  text-decoration:none;

  transition:transform .2s,*background* .2s,border-color .2s;

}

.ult-btn:hover {

  transform:translateY(-2px);

  background:var(--soft);border-color:var(--accent);

}

.ult-hero {

  display:block!important;height:auto!important;

  min-height:0!important;grid-template-columns:none!important;

  padding:42px 0 30px!important;margin:0!important;

  background:transparent!important;border:0!important;

}

.ult-kicker {

  color:var(--accent);font-size:10px;font-weight:850;

  letter-spacing:1.6px;text-transform:uppercase;

}

.ult-hero h1 {

  font:850 clamp(30px,4vw,43px)/1.2 'Manrope',sans-serif;

  letter-spacing:-1.7px;margin:12px 0;

}

.ult-desc {

  max-width:740px;color:var(--muted);

  font-size:13px;line-height:1.8;

}

.ult-tags {display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}

.ult-tag {

  background:var(--soft);border:1px solid var(--line);

  color:var(--text);padding:7px 11px;

  border-radius:8px;font-size:11px;font-weight:750;

}

.ult-stats {

  display:grid;grid-template-columns:repeat(4,minmax(0,1fr));

  gap:14px;margin-bottom:18px;

}

.ult-stat,.ult-panel {

  background:var(--surface);border:1px solid var(--line);

  border-radius:16px;min-width:0;

  transition:border-color .25s,transform .25s;

}

.ult-stat {padding:22px}

.ult-stat:hover {transform:translateY(-3px);border-color:var(--accent)}

.ult-stat-label {font-size:12px;color:var(--muted);font-weight:650}

.ult-stat-value {

  font:850 clamp(22px,3vw,33px) 'Manrope',sans-serif;

  letter-spacing:-1px;margin:13px 0 7px;

  overflow-wrap:anywhere;font-variant-numeric:tabular-nums;

}

.ult-stat-foot {color:var(--muted);font-size:11px;line-height:1.6}

.ult-grid {

  display:grid;grid-template-columns:1.1fr .9fr;

  gap:18px;margin-bottom:18px;

}

.ult-panel {padding:25px}

.ult-panel:hover {border-color:color-mix(in srgb,var(--accent) 35%,var(--line))}

.ult-panel h2 {

  font:800 18px 'Manrope',sans-serif;

  letter-spacing:-.5px;margin:8px 0;

}

.ult-panel-desc {

  color:var(--muted);font-size:12px;

  line-height:1.7;margin-bottom:20px!important;

}

.ult-row {padding:15px 0;border-bottom:1px solid var(--line)}

.ult-row:last-child {border-bottom:0}

.ult-row-top {

  display:flex;justify-content:space-between;

  align-items:center;gap:12px;

}

.ult-row-name {font-size:12px;font-weight:750;overflow-wrap:anywhere}

.ult-row-score {

  font-size:12px;font-weight:800;white-space:nowrap;

  font-variant-numeric:tabular-nums;

}

.ult-track {

  height:7px;background:var(--soft);border-radius:99px;

  overflow:hidden;margin-top:12px;

}

.ult-fill {

  height:100%;background:var(--accent);

  border-radius:99px;transform-origin:left;

  animation:ult-grow .9s ease-out both;

}

.ult-meta {

  font-size:11px;color:var(--muted);

  line-height:1.7;margin-top:6px;

}

.ult-pill {

  font-size:10px;font-weight:800;

  color:var(--accent);background:var(--soft);

  padding:6px 9px;border-radius:8px;white-space:nowrap;

}

.ult-priority {

  display:flex;justify-content:space-between;

  align-items:flex-start;gap:12px;

  padding:17px 0;border-bottom:1px solid var(--line);

}

.ult-priority:last-child {border-bottom:0}

.ult-priority h3 {font-size:12px;font-weight:800;margin-bottom:7px}

.ult-priority p {

  font-size:11px;color:var(--muted);line-height:1.7;

}

.ult-mistake {

  border:1px solid var(--line);

  border-radius:12px;padding:18px;margin-top:12px;

  transition:border-color .2s;

}

.ult-mistake:hover {border-color:var(--accent)}

.ult-question {

  font-size:13px;font-weight:750;

  line-height:1.7;margin:13px 0!important;

}

.ult-answer {

  color:var(--muted);font-size:12px;

  line-height:1.8;overflow-wrap:anywhere;

}

.ult-answer strong {color:var(--text)}

.ult-correct {color:#52c99a}

.ult-explanation {

  background:var(--soft);border-radius:10px;

  padding:13px;margin-top:12px;

  color:var(--muted);font-size:12px;

  line-height:1.8;white-space:pre-wrap;

}

.ult-empty {

  padding:20px;border:1px dashed var(--line);

  border-radius:12px;color:var(--muted);

  font-size:12px;line-height:1.8;

}

.ult-error {

  padding:15px;border:1px solid #b96b6b;

  border-radius:12px;margin-top:18px;font-size:12px;

}

.ult-enter {animation:ult-enter .5s ease-out both}

.ult-stats {animation-delay:.08s}

.ult-grid {animation-delay:.14s}

@keyframes ult-enter {

  from {opacity:0;transform:translateY(12px)}

  to {opacity:1;transform:translateY(0)}

}

@keyframes ult-grow {

  from {transform:scaleX(0)}

  to {transform:scaleX(1)}

}

@media(max-width:900px) {

  .ult-stats {grid-template-columns:repeat(2,minmax(0,1fr))}

  .ult-grid {grid-template-columns:1fr}

}

@media(max-width:520px) {

  .ult-page {padding:18px 14px 45px}

  .ult-hero {padding:30px 0 24px!important}

  .ult-stat,.ult-panel {padding:17px}

  .ult-stats {gap:10px}

  .ult-stat-value {font-size:24px}

  .ult-priority {flex-wrap:wrap}

}

@media(prefers-reduced-motion:reduce) {

  .ult-page * {animation:none!important;transition:none!important}

}


/* Compact layout: explanations and mistakes expand only when needed */
.ult-panel{padding:18px!important;min-height:0!important}
.ult-panel-desc{margin:5px 0 12px!important}
.ult-mistake{padding:13px!important;margin-top:9px!important}
.ult-question{margin:7px 0!important;line-height:1.45!important}
.ult-answer{line-height:1.5!important;margin:4px 0!important}
.ult-explanation{padding:10px 12px!important;margin-top:7px!important;line-height:1.5!important;white-space:normal!important}
.ult-explanation strong{display:block;margin-bottom:4px}
.ult-mistake details summary{cursor:pointer;font-size:12px;font-weight:750;color:var(--accent)}
.ult-mistake details[open] summary{margin-bottom:6px}
.ult-mistake details .ult-explanation{margin-top:0!important}
.ult-actions{gap:8px;flex-wrap:wrap}
</style>


<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/learning-ui-v2.css?v=2')) ?>">
</head>

<body>

<main class="ult-page">

<header class="ult-top">

  <a class="ult-logo"

     href="<?= e(url('dashboard/index.php')) ?>">

    u learn<span>.</span>

  </a>

  <nav class="ult-actions">
    <a class="ult-btn" href="<?= e(url('dashboard/ai-study.php')) ?>">✦ AI Study Assistant</a>

    <a class="ult-btn" href="<?= e($analyticsUrl) ?>">

      ← Performance Analytics

    </a>

    <a class="ult-btn"

       href="<?= e(url('dashboard/index.php')) ?>">

      Dashboard

    </a>

  </nav>

</header>

<?php if ($error !== ''): ?>

  <div class="ult-error"><?= e($error) ?></div>

<?php endif; ?>

<section class="ult-hero ult-enter">

  <div class="ult-kicker">Understand / Practise / Improve</div>

  <h1>Topic <span class="ult-purple">Weaknesses.</span></h1>

  <p class="ult-desc">

    Welcome back, <?= e($firstName) ?>.

    Discover which concepts need more attention, review

    incorrect answers, and build a stronger understanding

    through targeted practice.

  </p>

  <div class="ult-tags">

    <?php if ($moduleName !== ''): ?>

      <span class="ult-tag"><?= e($moduleName) ?></span>

    <?php endif; ?>

    <?php if ($profile): ?>

      <span class="ult-tag">

        <?= e((string)$profile['degree_name']) ?>

      </span>

      <span class="ult-tag">

        Batch <?= e((string)$profile['batch_code']) ?>

      </span>

    <?php endif; ?>

  </div>

</section>

<section class="ult-stats ult-enter">

  <article class="ult-stat">

    <div class="ult-stat-label">Answer accuracy</div>

    <div class="ult-stat-value">

      <?= $total ? number_format($accuracy,1).'%' : '—' ?>

    </div>

    <div class="ult-stat-foot">

      <?= $correct ?> correct of <?= $total ?> answered

    </div>

  </article>

  <article class="ult-stat">

    <div class="ult-stat-label">Topics analysed</div>

    <div class="ult-stat-value"><?= count($topics) ?></div>

    <div class="ult-stat-foot">Based on saved topic labels</div>

  </article>

  <article class="ult-stat">

    <div class="ult-stat-label">First focus topic</div>

    <div class="ult-stat-value" style="font-size:19px">

      <?= $focus ? e((string)$focus['topic']) : '—' ?>

    </div>

    <div class="ult-stat-foot">

      <?= $focus

          ? number_format((float)$focus['accuracy'],1).'% accuracy'

          : 'Complete a quiz first' ?>

    </div>

  </article>

  <article class="ult-stat">

    <div class="ult-stat-label">Recent mistakes</div>

    <div class="ult-stat-value"><?= count($mistakes) ?></div>

    <div class="ult-stat-foot">Latest 8 incorrect answers</div>

  </article>

</section>

<section class="ult-grid ult-enter">

  <article class="ult-panel">

    <div class="ult-kicker">Topic breakdown</div>

    <h2>Accuracy by topic</h2>

    <p class="ult-panel-desc">

      Lower accuracy appears first. Results based on fewer

      than five answers are preliminary.

    </p>

    <?php if (!$topics): ?>

      <div class="ult-empty">

        No topic results yet. Complete a quiz to unlock

        your topic breakdown.

      </div>

    <?php else: ?>

      <?php foreach ($topics as $i => $topic):

        $value = max(0,min(100,(float)$topic['accuracy']));

      ?>

        <div class="ult-row">

          <div class="ult-row-top">

            <span class="ult-row-name">

              <?= e((string)$topic['topic']) ?>

            </span>

            <span class="ult-row-score">

              <?= number_format($value,1) ?>%

            </span>

          </div>

          <div class="ult-track">

            <div class="ult-fill"

                 style="width:<?= $value ?>%;

                        animation-delay:<?= min($i,12)*0.05 ?>s">

            </div>

          </div>

          <div class="ult-meta">

            <?= (int)$topic['correct'] ?> correct /

            <?= (int)$topic['answered'] ?> answered

            <?php if ((int)$topic['answered'] < 5): ?>

              · Limited data

            <?php endif; ?>

          </div>

        </div>

      <?php endforeach; ?>

    <?php endif; ?>

  </article>

  <article class="ult-panel">

    <div class="ult-kicker">Personal focus plan</div>

    <h2>Where to focus next</h2>

    <p class="ult-panel-desc">

      Suggested practice based on your recorded results.

      These are not AI-generated recommendations.

    </p>

    <?php if (!$priorities): ?>

      <div class="ult-empty">

        Your revision priorities will appear here after

        completing quizzes.

      </div>

    <?php else: ?>

      <?php foreach ($priorities as $topic): ?>

        <div class="ult-priority">

          <div>

            <h3><?= e((string)$topic['topic']) ?></h3>

            <p>

              <?= (int)$topic['incorrect'] ?> incorrect of

              <?= (int)$topic['answered'] ?> answered.

              <?php if ((int)$topic['answered'] < 5): ?>

                Practise more questions to establish a

                reliable performance pattern.

              <?php elseif ((float)$topic['accuracy'] < 75): ?>

                Review the concept and retry related questions.

              <?php else: ?>

                Maintain your understanding through revision.

              <?php endif; ?>

            </p>

          </div>

          <span class="ult-pill">

            <?= topicPriority($topic) ?>

          </span>

        </div>

      <?php endforeach; ?>

    <?php endif; ?>

  </article>

</section>

<section class="ult-panel ult-enter">

  <div class="ult-kicker">Review and improve</div>

  <h2>Your recent mistakes</h2>

  <p class="ult-panel-desc">

    Learn from previously submitted incorrect answers.

    Each explanation comes from your saved quiz content.

  </p>

  <?php if (!$mistakes): ?>

    <div class="ult-empty">

      No incorrect answers found in your completed quizzes.

    </div>

  <?php else: ?>

    <?php foreach ($mistakes as $mistake): ?>

      <article class="ult-mistake">

        <div class="ult-row-top">

          <span class="ult-pill">

            <?= e((string)$mistake['topic']) ?>

          </span>

          <span class="ult-meta">

            <?= e((string)$mistake['quiz_title']) ?>

            ·

            <?= e(date(

                'd M Y',

                strtotime((string)$mistake['submitted_at'])

            )) ?>

          </span>

        </div>

        <p class="ult-question">

          <?= e((string)$mistake['question_text']) ?>

        </p>

        <div class="ult-answer">

          <strong>Your answer:</strong>

          <?= e((string)($mistake['selected_answer']

              ?? 'Not available')) ?>

        </div>

        <div class="ult-answer">

          <strong>Correct answer:</strong>

          <span class="ult-correct">

            <?= e((string)($mistake['correct_answer']

                ?? 'Not available')) ?>

          </span>

        </div>

        <?php if (trim((string)$mistake['explanation']) !== ''): ?>

          <details><summary>Show explanation</summary><div class="ult-explanation">

            <strong>Explanation</strong>

            <?= e((string)$mistake['explanation']) ?>

          </div></details>

        <?php endif; ?>

      </article>

    <?php endforeach; ?>

  <?php endif; ?>

  <div class="ult-actions" style="margin-top:12px">
    <a class="ult-btn" href="<?= e(url('dashboard/learning-insights.php')) ?>">Quiz-by-quiz insights ↗</a>

    <a class="ult-btn"

       href="<?= e(url('dashboard/quizzes.php')) ?>">

      Practise quizzes ↗

    </a>

    <a class="ult-btn" href="<?= e($analyticsUrl) ?>">

      Back to performance →

    </a>

  </div>

</section>

<footer style="text-align:center;color:var(--muted);

               font-size:11px;margin-top:35px">

  ULearn · Understand your weaknesses. Improve with practice.

</footer>

</main>

<?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body>

</html>
