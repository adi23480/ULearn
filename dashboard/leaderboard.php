
<?php
// ==========================================
// ULEARN - LEADERBOARD & ANALYTICS
// DATABASE CONNECTED VERSION
// ==========================================

// ==========================================
// 1. BACKEND API HANDLER
// ==========================================

if (isset($_GET['api']) && $_GET['api'] === 'fetch') {

    header('Content-Type: application/json');

    require_once __DIR__ . '/../config/bootstrap.php';

    try {

        // Get database connection
        $pdo = db();

        // Get logged-in user
        $currentUserId = $_SESSION['uid'] ?? null;

        if (!$currentUserId) {
            http_response_code(401);

            echo json_encode([
                'status' => 'error',
                'message' => 'User not logged in.'
            ]);

            exit;
        }

        // ==========================================
        // 2. GET ALL STUDENT SCORES
        // ==========================================

        $scoreQuery = "
            SELECT
                u.id,
                u.full_name,

                COALESCE(q.quiz_points, 0) +
                COALESCE(n.note_points, 0) AS total_score

            FROM users u

            LEFT JOIN (
                SELECT
                    user_id,
                    SUM(score) AS quiz_points
                FROM quiz_attempts
                WHERE status = 'submitted'
                GROUP BY user_id
            ) q ON u.id = q.user_id

            LEFT JOIN (
                SELECT
                    user_id,
                    SUM(points) AS note_points
                FROM student_note_points
                GROUP BY user_id
            ) n ON u.id = n.user_id

            WHERE u.role = 'student'

            ORDER BY total_score DESC, u.full_name ASC
        ";

        $scoreStmt = $pdo->query($scoreQuery);

        $allStudents = $scoreStmt->fetchAll(PDO::FETCH_ASSOC);

        // ==========================================
        // 3. TOP 10 LEADERBOARD
        // ==========================================

        $top10 = array_slice($allStudents, 0, 10);

        // ==========================================
        // 4. CURRENT USER RANK
        // ==========================================

        $currentUser = null;

        foreach ($allStudents as $index => $student) {

            if ((int)$student['id'] === (int)$currentUserId) {

                $currentUser = [
                    'full_name' => $student['full_name'],
                    'total_score' => (int)$student['total_score'],
                    'rank' => $index + 1
                ];

                break;
            }
        }

        // ==========================================
        // 5. SCORE BREAKDOWN
        // ==========================================

        $breakdownQuery = "

            SELECT
                'quizzes' AS activity_type,
                COALESCE(SUM(score), 0) AS total_points

            FROM quiz_attempts

            WHERE user_id = ?
            AND status = 'submitted'

            UNION ALL

            SELECT
                'notes' AS activity_type,
                COALESCE(SUM(points), 0) AS total_points

            FROM student_note_points

            WHERE user_id = ?

        ";

        $breakdownStmt = $pdo->prepare($breakdownQuery);

        $breakdownStmt->execute([
            $currentUserId,
            $currentUserId
        ]);

        $breakdown = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

        // ==========================================
        // 6. RECENT ACTIVITY HISTORY
        // ==========================================

        $historyQuery = "

            SELECT *

            FROM (

                SELECT
                    'quiz_completion' AS activity_type,

                    CONCAT(
                        'Completed Quiz #',
                        quiz_id
                    ) AS description,

                    score AS points,

                    submitted_at AS created_at

                FROM quiz_attempts

                WHERE user_id = ?
                AND status = 'submitted'

                UNION ALL

                SELECT
                    'note_upload' AS activity_type,

                    CONCAT(
                        'Earned points for Note #',
                        note_id
                    ) AS description,

                    points,

                    awarded_at AS created_at

                FROM student_note_points

                WHERE user_id = ?

            ) AS activities

            ORDER BY created_at DESC

            LIMIT 10

        ";

        $historyStmt = $pdo->prepare($historyQuery);

        $historyStmt->execute([
            $currentUserId,
            $currentUserId
        ]);

        $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // ==========================================
        // 7. JSON RESPONSE
        // ==========================================

        echo json_encode([

            'status' => 'success',

            'top10' => $top10,

            'current_user' => $currentUser,

            'breakdown' => $breakdown,

            'history' => $history,

            'recommendations' => []

        ]);

    } catch (PDOException $e) {

        http_response_code(500);

        error_log($e->getMessage());

        echo json_encode([
            'status' => 'error',
            'message' => 'Database error occurred.'
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        error_log($e->getMessage());

        echo json_encode([
            'status' => 'error',
            'message' => 'An unexpected error occurred.'
        ]);
    }

    exit;
}

$pageTitle = "ULearn - Live Analytics Dashboard";

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>

        :root {
            --bg-primary: #000000;
            --bg-sidebar: #0f1015;
            --bg-card: rgba(28, 28, 30, 0.75);
            --bg-card-hover: rgba(44, 44, 46, 0.85);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-main: #F5F5F7;
            --text-muted: #86868B;
            --accent-blue: #0A84FF;
            --accent-purple: #7c73e6;
            --accent-green: #30D158;
            --badge-live: #FF453A;
        }

        body {
            background-color: var(--bg-primary);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            margin: 0;
            padding: 0;
            display: flex;
            min-height: 100vh;
        }

        .app-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        .sidebar {
            width: 270px;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 24px 18px;
            position: sticky;
            top: 0;
            height: 100vh;
            box-sizing: border-box;
            overflow-y: auto;
        }

        .sidebar-brand {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 2px;
            margin-bottom: 25px;
            letter-spacing: -0.03em;
        }

        .sidebar-brand span {
            color: var(--accent-purple);
        }

        .workspace-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            letter-spacing: 0.08em;
            margin-bottom: 12px;
            padding-left: 10px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin-bottom: 4px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 12px;
            border-radius: 12px;
            color: var(--text-main);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .sidebar-menu a span.icon {
            font-size: 1rem;
            width: 20px;
            text-align: center;
            color: var(--text-main);
        }

        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .sidebar-menu a.active {
            background: rgba(124, 115, 230, 0.22);
            color: #dedbff;
            font-weight: 600;
            box-shadow: inset 0 0 0 1px rgba(124, 115, 230, 0.3);
        }

        .main-content {
            flex-grow: 1;
            padding: 30px 40px;
            overflow-y: auto;
            max-width: calc(100vw - 270px);
            box-sizing: border-box;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .dashboard-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.015em;
            color: var(--text-main);
            margin: 0;
        }

        .dashboard-header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.08);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }

        .live-badge {
            background-color: rgba(255, 69, 58, 0.15);
            color: var(--badge-live);
            border: 1px solid rgba(255, 69, 58, 0.3);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 1100px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .main-content {
                max-width: 100%;
                padding: 20px;
            }
        }

        .section-box {
            background: var(--bg-card);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid var(--border-color);
            padding: 20px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            margin-bottom: 20px;
        }

        .my-score-card {
            background: linear-gradient(135deg, rgba(10, 132, 255, 0.15), rgba(48, 209, 88, 0.15));
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid rgba(10, 132, 255, 0.25);
            color: var(--text-main);
            padding: 16px 20px;
            border-radius: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .my-score-card .rank {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent-green);
        }

        .leaderboard-list,
        .history-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .leaderboard-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255, 255, 255, 0.02);
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 8px;
            border: 1px solid var(--border-color);
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .leaderboard-item:hover {
            background: var(--bg-card-hover);
            transform: scale(1.002);
        }

        .rank-number {
            width: 28px;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .rank-1 {
            border-color: rgba(255, 215, 0, 0.35);
            background: linear-gradient(90deg, rgba(255, 215, 0, 0.06), rgba(28, 28, 30, 0.75));
        }

        .rank-1 .rank-number {
            color: #FFD700;
            font-weight: 700;
        }

        .rank-2 {
            border-color: rgba(192, 192, 192, 0.35);
            background: linear-gradient(90deg, rgba(192, 192, 192, 0.06), rgba(28, 28, 30, 0.75));
        }

        .rank-2 .rank-number {
            color: #E0E0E6;
            font-weight: 700;
        }

        .rank-3 {
            border-color: rgba(205, 127, 50, 0.35);
            background: linear-gradient(90deg, rgba(205, 127, 50, 0.06), rgba(28, 28, 30, 0.75));
        }

        .rank-3 .rank-number {
            color: #CD7F32;
            font-weight: 700;
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-grow: 1;
        }

        .student-avatar-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-main);
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .student-name {
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--text-main);
        }

        .score-value {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--accent-green);
        }

        .sub-section-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 14px;
            letter-spacing: -0.01em;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-container {
            position: relative;
            max-width: 260px;
            margin: 0 auto;
            height: 190px;
        }

        .recommendation-box {
            background: rgba(10, 132, 255, 0.06);
            border-left: 3px solid var(--accent-blue);
            padding: 10px 14px;
            border-radius: 0 10px 10px 0;
            margin-bottom: 8px;
            font-size: 0.82rem;
            color: #d1e7ff;
            border-top: 1px solid rgba(10, 132, 255, 0.08);
            border-right: 1px solid rgba(10, 132, 255, 0.08);
            border-bottom: 1px solid rgba(10, 132, 255, 0.08);
        }

        .row-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 9px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.82rem;
        }

        .row-item:last-child {
            border-bottom: none;
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 2px;
        }

        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-primary);
        }

        ::-webkit-scrollbar-thumb {
            background: #3a3a3c;
            border-radius: 3px;
        }

    </style>

</head>

<body>

<div class="app-container">

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <div>

            <div class="sidebar-brand">
                u learn<span>.</span>
            </div>

            <div class="workspace-label">YOUR WORKSPACE</div>

            <ul class="sidebar-menu">

                <li><a href="index.php"><span class="icon">◫</span> Overview</a></li>

                <li><a href="index.php#modules"><span class="icon">▤</span> My Modules</a></li>

                <li><a href="explore.php"><span class="icon">⊕</span> Explore & Enroll</a></li>

                <li><a href="notifications.php"><span class="icon">☩</span> Notifications</a></li>

                <li><a href="notes-library.php"><span class="icon">⊞</span> Notes Library</a></li>

                <li><a href="notes.php"><span class="icon">⬆</span> Upload My Notes</a></li>

                <li><a href="kuppiya.php"><span class="icon">☘</span> Kuppiya Sessions</a></li>

                <li><a href="quizzes.php"><span class="icon">⏱</span> My Quiz History</a></li>

                <li><a href="analytics.php"><span class="icon">⊙</span> Performance Analytics</a></li>

                <li><a href="topic-analysis.php"><span class="icon">◇</span> Topic Weaknesses</a></li>

                <li><a href="leaderboard.php" class="active"><span class="icon">🏆</span> Leaderboard</a></li>

                <li><a href="profile.php"><span class="icon">⊙</span> My Profile & Settings</a></li>

                <li><a href="index.php#academic"><span class="icon">◇</span> Academic Profile</a></li>

                <li><a href="index.php#features"><span class="icon">⊙</span> Learning Tools</a></li>

            </ul>

        </div>

    </aside>


    <!-- MAIN CONTENT -->

    <main class="main-content">

        <div class="dashboard-header">

            <h2>Student Analytics & Leaderboard</h2>

            <div class="dashboard-header-right">

                <span class="live-badge">● LIVE</span>

                <a href="index.php" class="btn-back">
                    <span>←</span> Back to Dashboard
                </a>

            </div>

        </div>


        <!-- CURRENT USER SCORE -->

        <div id="myScoreCard" class="my-score-card">

            <div>

                <div style="font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                    Your Standing
                </div>

                <strong id="myScoreName" style="font-size: 1.05rem; font-weight: 600;">
                    Loading...
                </strong>

            </div>

            <div style="text-align: right;">

                Rank: <span id="myRank" class="rank">--</span>

                <br>

                <span style="font-size: 0.78rem; color: var(--text-muted);">
                    Total:
                    <span id="myScore" style="color: var(--accent-green); font-weight: 600;">0</span>
                    pts
                </span>

            </div>

        </div>


        <!-- DASHBOARD GRID -->

        <div class="dashboard-grid">


            <!-- LEADERBOARD -->

            <div>

                <div class="section-box" style="height: 100%; box-sizing: border-box;">

                    <div class="sub-section-title">
                        🏆 Top 10 Leaderboard
                    </div>

                    <ul id="leaderboardList" class="leaderboard-list">

                        <li style="color: var(--text-muted);">
                            Loading leaderboard...
                        </li>

                    </ul>

                </div>

            </div>


            <!-- ANALYTICS -->

            <div>


                <!-- SCORE BREAKDOWN -->

                <div class="section-box">

                    <div class="sub-section-title">
                        📊 Score Analytics Breakdown
                    </div>

                    <div class="chart-container">

                        <canvas id="scorePieChart"></canvas>

                    </div>

                </div>


                <!-- RECOMMENDATIONS -->

                <div class="section-box">

                    <div class="sub-section-title">
                        💡 Areas for Improvement
                    </div>

                    <div id="recommendationsContainer">

                        <p style="color: var(--text-muted); font-size: 0.82rem;">
                            Recommendations will appear when performance analysis is implemented.
                        </p>

                    </div>

                </div>


                <!-- ACTIVITY HISTORY -->

                <div class="section-box">

                    <div class="sub-section-title">
                        🕒 Recent Activity Log
                    </div>

                    <div id="historyContainer">

                        <p style="color: var(--text-muted); font-size: 0.82rem;">
                            Loading tracking history...
                        </p>

                    </div>

                </div>


            </div>

        </div>

    </main>

</div>


<!-- ==========================================
     JAVASCRIPT
========================================== -->

<script>

let pieChartInstance = null;


// ==========================================
// FETCH DASHBOARD DATA
// ==========================================

async function updateDashboard() {

    try {

        const response = await fetch(
            'leaderboard.php?api=fetch',
            {
                cache: 'no-store'
            }
        );

        if (!response.ok) {
            throw new Error('Unable to fetch dashboard data.');
        }

        const data = await response.json();

        if (data.status !== 'success') {

            throw new Error(data.message || 'Dashboard update failed.');

        }


        // ==========================================
        // 1. UPDATE LEADERBOARD
        // ==========================================

        const listElement = document.getElementById('leaderboardList');

        listElement.innerHTML = '';

        if (data.top10 && data.top10.length > 0) {

            data.top10.forEach((student, index) => {

                const rank = index + 1;

                const fullName = student.full_name || 'User';

                const firstLetter = fullName.trim().charAt(0).toUpperCase();

                const li = document.createElement('li');

                li.className = `leaderboard-item rank-${rank}`;

                const rankNumber = document.createElement('div');

                rankNumber.className = 'rank-number';

                rankNumber.textContent = `#${rank}`;

                const studentInfo = document.createElement('div');

                studentInfo.className = 'student-info';

                const avatar = document.createElement('div');

                avatar.className = 'student-avatar-circle';

                avatar.textContent = firstLetter;

                const name = document.createElement('span');

                name.className = 'student-name';

                name.textContent = fullName;

                studentInfo.appendChild(avatar);

                studentInfo.appendChild(name);

                const score = document.createElement('div');

                score.className = 'score-value';

                score.textContent = `${Number(student.total_score) || 0} pts`;

                li.appendChild(rankNumber);

                li.appendChild(studentInfo);

                li.appendChild(score);

                listElement.appendChild(li);

            });

        } else {

            listElement.innerHTML =
                '<li style="color: var(--text-muted);">No leaderboard records available.</li>';

        }


        // ==========================================
        // 2. UPDATE CURRENT USER
        // ==========================================

        if (data.current_user) {

            document.getElementById('myScoreName').textContent =
                data.current_user.full_name;

            document.getElementById('myRank').textContent =
                `#${data.current_user.rank}`;

            document.getElementById('myScore').textContent =
                data.current_user.total_score;

        } else {

            document.getElementById('myScoreName').textContent =
                'No ranking available';

            document.getElementById('myRank').textContent = '--';

            document.getElementById('myScore').textContent = '0';

        }


        // ==========================================
        // 3. UPDATE CHART
        // ==========================================

        updatePieChart(data.breakdown);


        // ==========================================
        // 4. UPDATE RECOMMENDATIONS
        // ==========================================

        const recContainer =
            document.getElementById('recommendationsContainer');

        if (data.recommendations && data.recommendations.length > 0) {

            recContainer.innerHTML = '';

            data.recommendations.forEach(rec => {

                const recommendation = document.createElement('div');

                recommendation.className = 'recommendation-box';

                recommendation.textContent = rec;

                recContainer.appendChild(recommendation);

            });

        } else {

            recContainer.innerHTML =
                '<p style="color: var(--text-muted); font-size: 0.82rem;">Personalized recommendations are not available yet.</p>';

        }


        // ==========================================
        // 5. UPDATE ACTIVITY HISTORY
        // ==========================================

        const historyContainer =
            document.getElementById('historyContainer');

        historyContainer.innerHTML = '';

        if (data.history && data.history.length > 0) {

            const historyList = document.createElement('ul');

            historyList.className = 'history-list';

            data.history.forEach(hist => {

                const li = document.createElement('li');

                li.className = 'row-item';

                const activityInfo = document.createElement('div');

                const activityTitle = document.createElement('span');

                activityTitle.style.fontWeight = '500';

                activityTitle.style.color = 'var(--text-main)';

                activityTitle.textContent =
                    formatTitle(hist.activity_type);

                const activityDetails = document.createElement('small');

                activityDetails.style.color = 'var(--text-muted)';

                activityDetails.textContent =
                    `${hist.description || 'Activity completed'} • ${hist.created_at || ''}`;

                activityInfo.appendChild(activityTitle);

                activityInfo.appendChild(
                    document.createElement('br')
                );

                activityInfo.appendChild(activityDetails);

                const points = document.createElement('span');

                points.className = 'score-value';

                points.textContent =
                    `+${Number(hist.points) || 0} pts`;

                li.appendChild(activityInfo);

                li.appendChild(points);

                historyList.appendChild(li);

            });

            historyContainer.appendChild(historyList);

        } else {

            historyContainer.innerHTML =
                '<p style="color: var(--text-muted); font-size: 0.82rem;">No recent activity available.</p>';

        }


    } catch (error) {

        console.error('Dashboard update failed:', error);

        document.getElementById('leaderboardList').innerHTML =
            '<li style="color: #FF453A;">Unable to load leaderboard. Check database connection.</li>';

    }

}


// ==========================================
// DOUGHNUT CHART
// ==========================================

function updatePieChart(breakdownData) {

    const ctx = document
        .getElementById('scorePieChart')
        .getContext('2d');


    if (!breakdownData || breakdownData.length === 0) {

        if (pieChartInstance) {

            pieChartInstance.destroy();

            pieChartInstance = null;

        }

        return;

    }


    const labels = breakdownData.map(item =>
        formatTitle(item.activity_type)
    );

    const points = breakdownData.map(item =>
        Number(item.total_points) || 0
    );


    if (pieChartInstance) {

        pieChartInstance.data.labels = labels;

        pieChartInstance.data.datasets[0].data = points;

        pieChartInstance.update();

    } else {

        pieChartInstance = new Chart(ctx, {

            type: 'doughnut',

            data: {

                labels: labels,

                datasets: [{

                    data: points,

                    backgroundColor: [
                        '#0A84FF',
                        '#30D158',
                        '#FF9F0A',
                        '#5E5CE6',
                        '#FF453A',
                        '#64D2FF'
                    ],

                    borderWidth: 0,

                    hoverOffset: 4

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                cutout: '70%',

                plugins: {

                    legend: {

                        position: 'bottom',

                        labels: {

                            color: '#F5F5F7',

                            font: {

                                family: '-apple-system, BlinkMacSystemFont, "SF Pro Text"',

                                size: 10

                            },

                            boxWidth: 10,

                            padding: 10

                        }

                    }

                }

            }

        });

    }

}


// ==========================================
// FORMAT ACTIVITY TITLES
// ==========================================

function formatTitle(str) {

    if (!str) return '';

    return str
        .split('_')
        .map(word =>
            word.charAt(0).toUpperCase() + word.slice(1)
        )
        .join(' ');

}


// ==========================================
// INITIAL FETCH
// ==========================================

updateDashboard();


// Refresh dashboard every 10 seconds

setInterval(updateDashboard, 10000);

</script>

</body>

</html>
