<?php
// ==========================================
// 1. BACKEND API HANDLER (Self-Contained)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'fetch') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config/bootstrap.php';

    $currentUserId = $_SESSION['user_id'] ?? 1;

    try {
        // --- TODO: Replace these mock arrays with your actual database queries ---
        $top10 = [
            ['id' => 1, 'full_name' => 'Aaliyah Perera', 'total_score' => 1450],
            ['id' => 2, 'full_name' => 'Kasun Silva', 'total_score' => 1320],
            ['id' => 3, 'full_name' => 'Dinuka Fernando', 'total_score' => 1280],
            ['id' => 4, 'full_name' => 'Nimesh Ratnayake', 'total_score' => 1150],
            ['id' => 5, 'full_name' => 'Chamath Mendis', 'total_score' => 1090],
        ];

        $currentUser = [
            'full_name' => 'Upeksha',
            'total_score' => 1150,
            'rank' => 4
        ];

        $breakdown = [
            ['activity_type' => 'quizzes', 'total_points' => 550],
            ['activity_type' => 'assignments', 'total_points' => 400],
            ['activity_type' => 'forum_posts', 'total_points' => 200]
        ];

        $history = [
            ['activity_type' => 'quiz_completion', 'description' => 'Completed CS102 Quiz #3', 'points' => 50, 'created_at' => '2026-11-04 10:30:00']
        ];

        echo json_encode([
            'status' => 'success',
            'top10' => $top10,
            'current_user' => $currentUser,
            'breakdown' => $breakdown,
            'history' => $history
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
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
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- Chart.js CDN for Interactive Chart -->
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

        /* App Shell Layout */
        .app-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* Sidebar Styling */
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

        /* Main Content Panel */
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

        /* Layout Grid */
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

        .leaderboard-list, .history-list {
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

        /* Top Podiums */
        .rank-1 { border-color: rgba(255, 215, 0, 0.35); background: linear-gradient(90deg, rgba(255, 215, 0, 0.06) 0%, rgba(28, 28, 30, 0.75) 100%); }
        .rank-1 .rank-number { color: #FFD700; font-weight: 700; }

        .rank-2 { border-color: rgba(192, 192, 192, 0.35); background: linear-gradient(90deg, rgba(192, 192, 192, 0.06) 0%, rgba(28, 28, 30, 0.75) 100%); }
        .rank-2 .rank-number { color: #E0E0E6; font-weight: 700; }

        .rank-3 { border-color: rgba(205, 127, 50, 0.35); background: linear-gradient(90deg, rgba(205, 127, 50, 0.06) 0%, rgba(28, 28, 30, 0.75) 100%); }
        .rank-3 .rank-number { color: #CD7F32; font-weight: 700; }

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

        /* Scrollbar styling */
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 2px; }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-primary); }
        ::-webkit-scrollbar-thumb { background: #3a3a3c; border-radius: 3px; }
    </style>
</head>
<body>

<div class="app-container">
    
    <!-- Sidebar Navigation with Original Buttons + Leaderboard Added -->
    <aside class="sidebar">
        <div>
            <div class="sidebar-brand">
                u learn<span>.</span>
            </div>
            
            <div class="workspace-label">YOUR WORKSPACE</div>
            <ul class="sidebar-menu">
                <li><a href="index.php"><span class="icon">◫</span> Overview</a></li>
                <li><a href="modules.php"><span class="icon">▤</span> My Modules</a></li>
                <li><a href="explore.php"><span class="icon">⊕</span> Explore & Enroll</a></li>
                <li><a href="notifications.php"><span class="icon">☩</span> Notifications</a></li>
                <li><a href="notes.php"><span class="icon">⊞</span> Notes Library</a></li>
                <li><a href="upload-notes.php"><span class="icon">⬆</span> Upload My Notes</a></li>
                <li><a href="kuppiya.php"><span class="icon">☘</span> Kuppiya Sessions</a></li>
                <li><a href="quizzes.php"><span class="icon">⏱</span> My Quiz History</a></li>
                <li><a href="analytics.php"><span class="icon">⊙</span> Performance Analytics</a></li>
                <li><a href="topic-analysis.php"><span class="icon">◇</span> Topic Weaknesses</a></li>
                <li><a href="leaderboard.php" class="active"><span class="icon">🏆</span> Leaderboard</a></li>
                <li><a href="profile.php"><span class="icon">⊙</span> My Profile & Settings</a></li>
                <li><a href="profile.php"><span class="icon">◇</span> Academic Profile</a></li>
                <li><a href="learning-tools.php"><span class="icon">⊙</span> Learning Tools</a></li>
            </ul>
        </div>
    </aside>

    <!-- Main Content Panel -->
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

        <!-- Logged-in User Banner Card -->
        <div id="myScoreCard" class="my-score-card">
            <div>
                <div style="font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Your Standing</div>
                <strong id="myScoreName" style="font-size: 1.05rem; font-weight: 600;">Loading...</strong>
            </div>
            <div style="text-align: right;">
                Rank: <span id="myRank" class="rank">--</span><br>
                <span style="font-size: 0.78rem; color: var(--text-muted);">Total: <span id="myScore" style="color: var(--accent-green); font-weight: 600;">0</span> pts</span>
            </div>
        </div>

        <!-- Modern Two-Column Dashboard Grid -->
        <div class="dashboard-grid">
            
            <!-- Left Column: Top 10 Leaderboard -->
            <div>
                <div class="section-box" style="height: 100%; box-sizing: border-box;">
                    <div class="sub-section-title">🏆 Top 10 Leaderboard</div>
                    <ul id="leaderboardList" class="leaderboard-list">
                        <!-- Populated via JS -->
                    </ul>
                </div>
            </div>

            <!-- Right Column: Analytics Charts, Recommendations & Logs -->
            <div>
                <!-- Score Analytics Chart Section -->
                <div class="section-box">
                    <div class="sub-section-title">📊 Score Analytics Breakdown</div>
                    <div class="chart-container">
                        <canvas id="scorePieChart"></canvas>
                    </div>
                </div>

                <!-- Quiz Analysis & Recommendations -->
                <div class="section-box">
                    <div class="sub-section-title">💡 Areas for Improvement</div>
                    <div id="recommendationsContainer">
                        <p style="color: var(--text-muted); font-size: 0.82rem;">Analyzing performance...</p>
                    </div>
                </div>

                <!-- Activity Tracking History Section -->
                <div class="section-box">
                    <div class="sub-section-title">🕒 Recent Activity Log</div>
                    <div id="historyContainer">
                        <p style="color: var(--text-muted); font-size: 0.82rem;">Loading tracking history...</p>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
let pieChartInstance = null;

async function updateDashboard() {
    try {
        const response = await fetch('leaderboard.php?api=fetch');
        const data = await response.json();

        if (data.status === 'success') {
            // Update Top 10 List
            const listElement = document.getElementById('leaderboardList');
            listElement.innerHTML = '';

            data.top10.forEach((student, index) => {
                const rank = index + 1;
                const fullName = student.full_name || 'User';
                const firstLetter = fullName.trim().charAt(0).toUpperCase();

                const itemHtml = `
                    <li class="leaderboard-item rank-${rank}">
                        <div class="rank-number">#${rank}</div>
                        <div class="student-info">
                            <div class="student-avatar-circle">${firstLetter}</div>
                            <span class="student-name">${escapeHtml(fullName)}</span>
                        </div>
                        <div class="score-value">${student.total_score} pts</div>
                    </li>
                `;
                listElement.insertAdjacentHTML('beforeend', itemHtml);
            });

            // Update User Card Banner
            if (data.current_user) {
                document.getElementById('myScoreName').textContent = data.current_user.full_name;
                document.getElementById('myRank').textContent = `#${data.current_user.rank}`;
                document.getElementById('myScore').textContent = data.current_user.total_score;
            }

            // Update Doughnut Chart Analytics
            updatePieChart(data.breakdown);

            // Update Recommendations Box
            const recContainer = document.getElementById('recommendationsContainer');
            if (data.recommendations && data.recommendations.length > 0) {
                let recHtml = '';
                data.recommendations.forEach(rec => {
                    recHtml += `<div class="recommendation-box">${rec}</div>`;
                });
                recContainer.innerHTML = recHtml;
            } else {
                recContainer.innerHTML = '<p style="color: var(--text-muted); font-size: 0.82rem;">Complete more modules to unlock personalized recommendations.</p>';
            }

            // Update Activity Tracking Log
            const historyContainer = document.getElementById('historyContainer');
            if (data.history && data.history.length > 0) {
                let historyHtml = '<ul class="history-list">';
                data.history.forEach(hist => {
                    historyHtml += `
                        <li class="row-item">
                            <div>
                                <span style="font-weight: 500; color: var(--text-main);">${formatTitle(hist.activity_type)}</span><br>
                                <small style="color: var(--text-muted);">${escapeHtml(hist.description || 'Completed')} • ${hist.created_at}</small>
                            </div>
                            <span class="score-value">+${hist.points} pts</span>
                        </li>
                    `;
                });
                historyHtml += '</ul>';
                historyContainer.innerHTML = historyHtml;
            } else {
                historyContainer.innerHTML = '<p style="color: var(--text-muted); font-size: 0.82rem;">No recent point tracking history.</p>';
            }
        }
    } catch (error) {
        console.error("Dashboard update failed:", error);
    }
}

// Render or Update Chart.js Doughnut Chart with Apple UI Color Palette
function updatePieChart(breakdownData) {
    const ctx = document.getElementById('scorePieChart').getContext('2d');
    
    if (!breakdownData || breakdownData.length === 0) {
        if (pieChartInstance) pieChartInstance.destroy();
        return;
    }

    const labels = breakdownData.map(item => formatTitle(item.activity_type));
    const points = breakdownData.map(item => parseInt(item.total_points));

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
                        '#0A84FF', '#30D158', '#FF9F0A', '#5E5CE6', '#FF453A', '#64D2FF'
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

function formatTitle(str) {
    if (!str) return '';
    return str.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

// Initial fetch and 1-second auto-refresh loop
updateDashboard();
setInterval(updateDashboard, 1000);
</script>

</body>
</html>