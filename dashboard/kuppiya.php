<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

// University-local scheduling; do not rely on the operating system default timezone.
date_default_timezone_set('Asia/Colombo');

$user = require_login();

if (($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('Student access only.');
}

$pdo = db();
// Keep MySQL NOW() aligned with PHP's Sri Lanka-local DATETIME values.
$pdo->exec("SET time_zone = '+05:30'");
$uid = (int)$user['id'];
$error = '';
$notice = (string)($_GET['notice'] ?? '');
$token = (string)($_GET['invite'] ?? '');
$sessionId = filter_var($_GET['room'] ?? $_GET['session'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$view = (($_GET['view'] ?? '') === 'my') ? 'my' : 'discover';


function kpDate(string $raw): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
    return $d && $d->format('Y-m-d\TH:i') === $raw ? $d : null;
}

function kpRedirect(string $notice, int $id = 0): never
{
    go('dashboard/kuppiya.php?notice=' . rawurlencode($notice) . ($id ? '&room=' . $id : '&view=my'));
}

function kpLock(PDO $pdo, int $id): array
{
    $q = $pdo->prepare('SELECT * FROM kuppiya_sessions WHERE id=? FOR UPDATE');
    $q->execute([$id]);

    $s = $q->fetch(PDO::FETCH_ASSOC);

    if (!$s) {
        throw new RuntimeException('Session not found.');
    }

    return $s;
}

function kpMember(PDO $pdo, int $id, int $uid): bool
{
    $q = $pdo->prepare('SELECT 1 FROM kuppiya_members WHERE session_id=? AND user_id=?');
    $q->execute([$id, $uid]);

    return (bool)$q->fetchColumn();
}

function kpConflict(PDO $pdo, int $uid, string $start, string $end): bool
{
    $q = $pdo->prepare("
        SELECT 1
        FROM kuppiya_members km
        JOIN kuppiya_sessions ks ON ks.id=km.session_id
        WHERE km.user_id=?
        AND ks.status='scheduled'
        AND ks.ends_at>NOW()
        AND ks.starts_at<?
        AND ks.ends_at>?
        LIMIT 1
    ");

    $q->execute([$uid, $end, $start]);

    return (bool)$q->fetchColumn();
}

function kpSystem(PDO $pdo, int $id, string $text): void
{
    $q = $pdo->prepare("
        INSERT INTO kuppiya_messages
        (session_id,sender_user_id,message_type,message_text)
        VALUES (?,NULL,'system',?)
    ");

    $q->execute([$id, $text]);
}

function kpNotify(
    PDO $pdo,
    int $recipient,
    int $actor,
    string $type,
    string $title,
    string $message,
    int $sessionId
): void {
    $q = $pdo->prepare("
        INSERT INTO notifications
        (user_id, actor_user_id, type, title, message, target_url)
        VALUES (?,?,?,?,?,?)
    ");

    $q->execute([
        $recipient,
        $actor,
        $type,
        $title,
        $message,
        url('dashboard/kuppiya-room.php?id=' . $sessionId)
    ]);
}

function kpNotifyMembers(
    PDO $pdo,
    int $sessionId,
    int $actor,
    string $type,
    string $title,
    string $message
): void {
    $q = $pdo->prepare(
        'SELECT user_id FROM kuppiya_members WHERE session_id=? AND user_id<>?'
    );

    $q->execute([$sessionId, $actor]);

    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $recipient) {
        kpNotify($pdo, (int)$recipient, $actor, $type, $title, $message, $sessionId);
    }
}


try {

    // Cancel sessions with fewer than two members.

    $pdo->beginTransaction();

    $q = $pdo->query("
        SELECT ks.id, ks.title
        FROM kuppiya_sessions ks
        WHERE ks.status='scheduled'
        AND ks.starts_at<=NOW()
        AND (
            SELECT COUNT(*)
            FROM kuppiya_members km
            WHERE km.session_id=ks.id
        )<2
        FOR UPDATE
    ");

    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $expired) {

        $cancel = $pdo->prepare("
            UPDATE kuppiya_sessions
            SET status='cancelled'
            WHERE id=? AND status='scheduled'
        ");

        $cancel->execute([(int)$expired['id']]);

        if ($cancel->rowCount() > 0) {

            $members = $pdo->prepare(
                'SELECT user_id FROM kuppiya_members WHERE session_id=?'
            );

            $members->execute([(int)$expired['id']]);

            foreach ($members->fetchAll(PDO::FETCH_COLUMN) as $memberId) {

                kpNotify(
                    $pdo,
                    (int)$memberId,
                    (int)$memberId,
                    'kuppiya_cancelled',
                    'Kuppiya cancelled',
                    'Your Kuppiya "' . $expired['title'] . '" was cancelled because fewer than two students joined.',
                    (int)$expired['id']
                );
            }
        }
    }

    $pdo->commit();


    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

        csrf_check();

        $action = (string)($_POST['action'] ?? '');


        // CREATE SESSION

        if ($action === 'create') {

            $moduleId = null;

            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $location = (string)($_POST['location_type'] ?? 'online');
            $details = trim((string)($_POST['meeting_details'] ?? ''));
            $date = trim((string)($_POST['session_date'] ?? ''));
            $clock = trim((string)($_POST['start_time'] ?? ''));

            $duration = filter_var(
                $_POST['duration_minutes'] ?? null,
                FILTER_VALIDATE_INT
            );

            $validDurations = [30, 60, 90, 120, 180];

            if (
                !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ||
                !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $clock) ||
                !in_array($duration, $validDurations, true)
            ) {
                throw new RuntimeException('Choose a valid calendar date, start time and duration.');
            }

            $start = kpDate($date . 'T' . $clock);
            $end = $start ? $start->modify('+' . $duration . ' minutes') : null;

            if (
                $title === '' ||
                mb_strlen($title) > 180 ||
                mb_strlen($description) > 3000 ||
                mb_strlen($details) > 500
            ) {
                throw new RuntimeException('Check title, description and meeting details lengths.');
            }

            if (!in_array($location, ['online', 'in_person'], true)) {
                throw new RuntimeException('Choose a meeting type.');
            }

            if (
                !$start ||
                !$end ||
                $start <= new DateTimeImmutable('now') ||
                $end <= $start ||
                $end->getTimestamp() - $start->getTimestamp() > 21600
            ) {
                throw new RuntimeException('Choose a future session lasting up to 6 hours.');
            }

            $st = $start->format('Y-m-d H:i:s');
            $en = $end->format('Y-m-d H:i:s');

            $pdo->beginTransaction();

            $q = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $q->execute([$uid]);

            if (kpConflict($pdo, $uid, $st, $en)) {
                throw new RuntimeException('You already have a Kuppiya during this time.');
            }

            $invite = bin2hex(random_bytes(24));

            $q = $pdo->prepare("
                INSERT INTO kuppiya_sessions
                (module_id,host_user_id,invite_token,title,description,
                location_type,meeting_details,starts_at,ends_at,max_members,status)
                VALUES (?,?,?,?,?,?,?,?,?,12,'scheduled')
            ");

            $q->execute([
                $moduleId,
                $uid,
                $invite,
                $title,
                $description ?: null,
                $location,
                $details ?: null,
                $st,
                $en
            ]);

            $id = (int)$pdo->lastInsertId();

            $q = $pdo->prepare(
                'INSERT INTO kuppiya_members(session_id,user_id) VALUES (?,?)'
            );

            $q->execute([$id, $uid]);

            kpSystem($pdo, $id, 'Kuppiya created. Waiting for at least one more member.');

            $pdo->commit();

            kpRedirect('Session created.', $id);
        }


        $id = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$id) {
            throw new RuntimeException('Invalid session.');
        }


        // SEND CHAT MESSAGE

        if ($action === 'chat') {

            $message = trim((string)($_POST['message'] ?? ''));

            if ($message === '' || mb_strlen($message) > 2000) {
                throw new RuntimeException('Message must be 1–2000 characters.');
            }

            $pdo->beginTransaction();

            $s = kpLock($pdo, $id);

            if (
                $s['status'] !== 'scheduled' ||
                strtotime($s['ends_at']) <= time() ||
                !kpMember($pdo, $id, $uid)
            ) {
                throw new RuntimeException('This chat is only available to current members of an active session.');
            }

            $q = $pdo->prepare("
                INSERT INTO kuppiya_messages
                (session_id,sender_user_id,message_type,message_text)
                VALUES (?,?,'chat',?)
            ");

            $q->execute([$id, $uid, $message]);

            kpNotifyMembers(
                $pdo, $id, $uid, 'kuppiya_message',
                'New Kuppiya message',
                ($user['full_name'] ?? 'A member') . ' sent a message in ' . $s['title'] . '.'
            );

            $pdo->commit();

            go('dashboard/kuppiya.php?room=' . $id . '#kuppiya-chat');
        }


        // JOIN SESSION

        if ($action === 'join') {

            $pdo->beginTransaction();

            $q = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $q->execute([$uid]);

            $s = kpLock($pdo, $id);

            if (
                $s['status'] !== 'scheduled' ||
                strtotime($s['starts_at']) <= time()
            ) {
                throw new RuntimeException('This session is no longer open for joining.');
            }

            if (kpMember($pdo, $id, $uid)) {
                throw new RuntimeException('You are already a member.');
            }

            $q = $pdo->prepare(
                'SELECT COUNT(*) FROM kuppiya_members WHERE session_id=?'
            );

            $q->execute([$id]);

            if ((int)$q->fetchColumn() >= min(12, (int)$s['max_members'])) {
                throw new RuntimeException('This group is full.');
            }

            if (kpConflict($pdo, $uid, $s['starts_at'], $s['ends_at'])) {
                throw new RuntimeException('You have another Kuppiya at an overlapping time.');
            }

            $q = $pdo->prepare(
                'INSERT INTO kuppiya_members(session_id,user_id) VALUES (?,?)'
            );

            $q->execute([$id, $uid]);

            kpSystem($pdo, $id, 'A new student joined the group.');

            if ((int)$s['host_user_id'] !== $uid) {

                kpNotify(
                    $pdo,
                    (int)$s['host_user_id'],
                    $uid,
                    'kuppiya_member_joined',
                    'New Kuppiya member',
                    'A student joined your Kuppiya "' . $s['title'] . '".',
                    $id
                );
            }

            $pdo->commit();

            kpRedirect('Joined successfully.', $id);
        }


        // LEAVE SESSION

        if ($action === 'leave') {

            $pdo->beginTransaction();

            $s = kpLock($pdo, $id);

            if (
                $s['status'] !== 'scheduled' ||
                strtotime($s['starts_at']) <= time()
            ) {
                throw new RuntimeException('You can only leave before the session starts.');
            }

            if (!kpMember($pdo, $id, $uid)) {
                throw new RuntimeException('You are not a member.');
            }

            $q = $pdo->prepare(
                'DELETE FROM kuppiya_members WHERE session_id=? AND user_id=?'
            );

            $q->execute([$id, $uid]);

            if ((int)$s['host_user_id'] === $uid) {

                $q = $pdo->prepare(
                    'SELECT user_id FROM kuppiya_members WHERE session_id=? ORDER BY user_id'
                );

                $q->execute([$id]);

                $remaining = array_map(
                    'intval',
                    $q->fetchAll(PDO::FETCH_COLUMN)
                );

                if (!$remaining) {

                    $q = $pdo->prepare(
                        "UPDATE kuppiya_sessions SET status='cancelled' WHERE id=?"
                    );

                    $q->execute([$id]);

                } else {

                    $newHost = $remaining[random_int(0, count($remaining) - 1)];

                    $q = $pdo->prepare(
                        'UPDATE kuppiya_sessions SET host_user_id=? WHERE id=?'
                    );

                    $q->execute([$newHost, $id]);

                    kpSystem(
                        $pdo,
                        $id,
                        'The previous host left. A new host was selected automatically.'
                    );

                    kpNotify(
                        $pdo,
                        $newHost,
                        $uid,
                        'kuppiya_host_transfer',
                        'You are now the Kuppiya host',
                        'You were automatically selected to host "' . $s['title'] . '".',
                        $id
                    );

                    kpNotifyMembers(
                        $pdo,
                        $id,
                        $newHost,
                        'kuppiya_host_changed',
                        'Kuppiya host changed',
                        'The host of "' . $s['title'] . '" has changed.'
                    );
                }

            } else {

                kpSystem($pdo, $id, 'A student left the group.');

                kpNotify(
                    $pdo,
                    (int)$s['host_user_id'],
                    $uid,
                    'kuppiya_member_left',
                    'Kuppiya member left',
                    'A student left your Kuppiya "' . $s['title'] . '".',
                    $id
                );
            }

            $pdo->commit();

            kpRedirect('You left the session.');
        }

        throw new RuntimeException('Unknown action.');
    }


    // INVITATION LINK

    if ($token !== '' && preg_match('/^[a-f0-9]{48}$/D', $token)) {

        $q = $pdo->prepare(
            'SELECT id FROM kuppiya_sessions WHERE invite_token=?'
        );

        $q->execute([$token]);

        $sessionId = (int)($q->fetchColumn() ?: 0);

        if (!$sessionId) {
            $error = 'This invitation link is invalid.';
        }
    }


    // UPCOMING SESSIONS

    $q = $pdo->prepare("
        SELECT ks.*,
        u.full_name AS host_name,
        (SELECT COUNT(*) FROM kuppiya_members km WHERE km.session_id=ks.id) AS member_count,
        EXISTS(
            SELECT 1 FROM kuppiya_members km2
            WHERE km2.session_id=ks.id AND km2.user_id=?
        ) AS is_member
        FROM kuppiya_sessions ks
        JOIN users u ON u.id=ks.host_user_id
        WHERE ks.status='scheduled'
        AND ks.ends_at>NOW()
        ORDER BY ks.starts_at ASC
        LIMIT 100
    ");

    $q->execute([$uid]);

    $upcoming = $q->fetchAll(PDO::FETCH_ASSOC);


    // MY KUPPIYAS

    $q = $pdo->prepare("
        SELECT ks.id,ks.title,ks.starts_at,ks.ends_at,ks.status
        FROM kuppiya_members km
        JOIN kuppiya_sessions ks ON ks.id=km.session_id
        WHERE km.user_id=?
        ORDER BY ks.starts_at DESC
        LIMIT 50
    ");

    $q->execute([$uid]);

    $mine = $q->fetchAll(PDO::FETCH_ASSOC);


    $selected = null;
    $members = [];
    $messages = [];


    // SELECTED SESSION

    if ($sessionId) {

        $q = $pdo->prepare("
            SELECT ks.*,
            u.full_name AS host_name,
            (SELECT COUNT(*) FROM kuppiya_members WHERE session_id=ks.id) AS member_count
            FROM kuppiya_sessions ks
            JOIN users u ON u.id=ks.host_user_id
            WHERE ks.id=?
        ");

        $q->execute([$sessionId]);

        $selected = $q->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$selected) {

            $error = 'Kuppiya not found.';

        } elseif (kpMember($pdo, $sessionId, $uid)) {

            $q = $pdo->prepare("
                SELECT u.full_name,km.user_id
                FROM kuppiya_members km
                JOIN users u ON u.id=km.user_id
                WHERE km.session_id=?
                ORDER BY km.joined_at
            ");

            $q->execute([$sessionId]);

            $members = $q->fetchAll(PDO::FETCH_ASSOC);


            $q = $pdo->prepare("
                SELECT msg.sender_user_id,msg.message_type,
                msg.message_text,msg.created_at,u.full_name
                FROM kuppiya_messages msg
                LEFT JOIN users u ON u.id=msg.sender_user_id
                WHERE msg.session_id=?
                ORDER BY msg.id DESC
                LIMIT 100
            ");

            $q->execute([$sessionId]);

            $messages = array_reverse(
                $q->fetchAll(PDO::FETCH_ASSOC)
            );
        }
    }

} catch (Throwable $ex) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $ex instanceof RuntimeException
        ? $ex->getMessage()
        : 'A database error occurred. Please verify the Stage 7 tables.';
}


$initial = strtoupper(
    substr(trim((string)($user['full_name'] ?? 'S')), 0, 1)
);

$kpBatch = '';
try {
    $kpBatchStmt = $pdo->prepare("SELECT ab.batch_code FROM student_academic_profiles sap JOIN academic_batches ab ON ab.id=sap.batch_id WHERE sap.user_id=? LIMIT 1");
    $kpBatchStmt->execute([$uid]);
    $kpBatch = (string)($kpBatchStmt->fetchColumn() ?: '');
} catch (Throwable $kpBatchError) { /* Batch badge is optional; preserve Kuppiya availability. */ }

$base = url('dashboard/kuppiya.php');

// Count unread notifications for badge consistency
$unreadNotificationsStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadNotificationsStmt->execute([$uid]);
$unreadNotifications = (int)$unreadNotificationsStmt->fetchColumn();

// Fetch note contribution points for sidebar bottom display
$notePointsStmt = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM student_note_points WHERE user_id = ?');
$notePointsStmt->execute([$uid]);
$notePoints = (int)$notePointsStmt->fetchColumn();
?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Kuppiya Sessions — ULearn</title>


<!-- KUPPIYA THEME + INVITATION JAVASCRIPT -->
<script>
(function () {
    'use strict';
    var preferenceKey = 'ulearn-theme-preference';
    var legacyKey = 'ulearn-theme';

    function resolveTheme() {
        try {
            var preference = localStorage.getItem(preferenceKey);
            if (preference === 'system') {
                return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            if (preference === 'dark' || preference === 'light') return preference;
            var legacy = localStorage.getItem(legacyKey);
            if (legacy === 'dark' || legacy === 'light') return legacy;
        } catch (e) {}
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    document.documentElement.setAttribute('data-theme', resolveTheme());

    function updateThemeButton() {
        var button = document.getElementById('themeButton');
        if (!button) return;
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        button.textContent = dark ? '\u2600' : '\u263E';
        button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        button.setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');
    }

    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try {
            localStorage.setItem(preferenceKey, theme);
            localStorage.setItem(legacyKey, theme);
        } catch (e) {}
        updateThemeButton();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('themeButton');
        if (button) {
            button.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-theme');
                setTheme(current === 'dark' ? 'light' : 'dark');
            });
        }
        updateThemeButton();
    });

    window.copyInvite = function (id) {
        var field = document.getElementById(id);
        if (!field) return;
        var value = field.value;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value)
                .then(function () { alert('Invitation copied!'); })
                .catch(function () { window.prompt('Copy this link:', value); });
        } else {
            field.focus();
            field.select();
            try {
                if (document.execCommand('copy')) alert('Invitation copied!');
                else window.prompt('Copy this link:', value);
            } catch (error) {
                window.prompt('Copy this link:', value);
            }
        }
    };
})();
</script>


<style>

/* LIGHT MODE */

:root {
    color-scheme: light;
    --bg: #f7f8fc;
    --surface: #ffffff;
    --surface-alt: #f1f2f8;
    --text: #171927;
    --muted: #777b90;
    --border: #e7e8f0;
    --accent: #6756e8;
    --accent-light: #f0edff;
    --shadow: 0 10px 40px rgba(30,30,60,.04);
    --green: #087e60;
    --soft: #e7f7ef;
    --soft-accent: #f0edff;
}


/* DARK MODE */

:root[data-theme="dark"] {
    color-scheme: dark;
    --bg: #0c0e16;
    --surface: #161924;
    --surface-alt: #202431;
    --text: #f4f4fa;
    --muted: #a0a4b6;
    --border: #2b2e3c;
    --accent: #a99aff;
    --accent-light: #292440;
    --shadow: none;
    --green: #5ce3b5;
    --soft: #153d34;
    --soft-accent: #292440;
}


* {
    box-sizing: border-box;
}


body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font: 15px/1.6 Inter, system-ui, Arial, sans-serif;
    transition: background .25s ease, color .25s ease;
}


a {
    color: inherit;
    text-decoration: none;
}


.layout {
    display: grid;
    grid-template-columns: 250px minmax(0, 1fr);
    min-height: 100vh;
}


.sidebar {
    background: var(--surface);
    border-right: 1px solid var(--border);
    padding: 32px 20px;
    display: flex;
    flex-direction: column;
    gap: 40px;
}


.brand {
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -1.5px;
    padding-left: 12px;
}


.brand span,
.accent {
    color: var(--accent);
}


.nav-label,
.eyebrow {
    font-size: 11px;
    font-weight: 750;
    letter-spacing: 1.7px;
    text-transform: uppercase;
    color: var(--muted);
}


.nav-label {
    margin: 0 12px 14px;
}


.nav {
    display: grid;
    gap: 6px;
}


.nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
}


.nav a:hover {
    background: var(--surface-alt);
}


.nav a.active {
    background: var(--soft-accent);
    color: var(--accent);
}


.ul-nav-icon {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}


.sidebar-bottom {
    margin-top: auto;
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px;
    font-size: 13px;
}
.sidebar-bottom strong { display: block; overflow-wrap: anywhere; }
.sidebar-bottom small { color: var(--muted); overflow-wrap: anywhere; }


main {
    min-width: 0;
    padding: 34px clamp(20px, 5vw, 76px) 80px;
}


.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 40px;
}


.right {
    display: flex;
    align-items: center;
    gap: 16px;
}


/* NOTIFICATION BELL */
.notification-bell {
    width: 42px; height: 42px; position: relative;
    display: inline-grid; place-items: center;
    background: var(--surface); color: var(--text);
    border: 1px solid var(--border); border-radius: 12px;
    box-shadow: var(--shadow);
    transition: transform .18s ease, background .18s ease, border-color .18s ease;
}
.notification-bell:hover { transform: translateY(-2px); background: var(--soft-accent); border-color: var(--accent); }
.notification-bell svg { width: 21px; height: 21px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.notification-count {
    position: absolute; top: -8px; right: -9px;
    min-width: 20px; height: 20px; padding: 0 5px;
    display: inline-grid; place-items: center;
    border-radius: 999px; background: #e34878; color: #fff;
    border: 2px solid var(--bg); font-size: 10px; font-weight: 800;
    line-height: 1;
}


/* DARK MODE BUTTON */

.theme-button {
    width: 42px;
    height: 42px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface);
    color: var(--text);
    font-size: 20px;
    cursor: pointer;
    transition: .2s;
}

.theme-button:hover {
    background: var(--soft-accent);
    color: var(--accent);
    transform: scale(1.05);
}


.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--soft-accent);
    color: var(--accent);
    display: grid;
    place-items: center;
    font-weight: 800;
}


.logout {
    background: transparent; border: 0; color: var(--muted);
    font-size: 13px; font-weight: 650; cursor: pointer;
}
.logout:hover { color: var(--text); }


h1 {
    font-size: clamp(34px, 4vw, 54px);
    letter-spacing: -2px;
    line-height: 1.15;
    margin: 12px 0;
}


h2 {
    font-size: 23px;
    letter-spacing: -.5px;
    margin: 0 0 18px;
}


h3 {
    margin: 0 0 8px;
}


.lead,
.muted,
.meta {
    color: var(--muted);
}


.section {
    margin-top: 42px;
}


.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    transition: background .25s, border-color .25s;
}


.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}


.field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}


.field.full {
    grid-column: 1 / -1;
}


label {
    font-weight: 650;
}


input,
select,
textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--bg);
    color: var(--text);
    font: inherit;
}


input:focus,
select:focus,
textarea:focus {
    outline: 2px solid var(--accent);
    border-color: var(--accent);
}


input::placeholder,
textarea::placeholder {
    color: var(--muted);
}


.btn {
    display: inline-block;
    padding: 11px 17px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    font: inherit;
    font-weight: 650;
    transition: .2s;
}


.btn:hover {
    background: var(--soft-accent);
    color: var(--accent);
}


.btn.primary {
    background: var(--accent);
    border-color: var(--accent);
    color: #ffffff;
}


.btn.primary:hover {
    opacity: .88;
}


.notice {
    padding: 13px 16px;
    margin: 15px 0;
    border-radius: 10px;
    background: var(--soft-accent);
    color: var(--text);
}


.notice.error {
    background: #ffe7e7;
    color: #a52222;
}


:root[data-theme="dark"] .notice.error {
    background: #49252b;
    color: #ffb9bd;
}


.tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 30px;
}


.tab {
    padding: 10px 16px;
    border-radius: 10px;
    background: var(--surface);
    border: 1px solid var(--border);
}


.tab.active {
    background: var(--soft-accent);
    color: var(--accent);
    border-color: var(--accent);
}


.pill {
    display: inline-block;
    padding: 5px 11px;
    border-radius: 20px;
    background: var(--soft-accent);
    color: var(--accent);
    font-size: 12px;
    font-weight: 700;
}


.stack {
    display: flex;
    flex-direction: column;
    gap: 14px;
}


.flex {
    display: flex;
    align-items: center;
    gap: 15px;
}


.session {
    min-width: 0;
}


.back {
    display: inline-block;
    margin-bottom: 15px;
    color: var(--accent);
    font-weight: 700;
}


.empty {
    padding: 25px;
    text-align: center;
    color: var(--muted);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
}


.room-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin: 20px 0;
}


.room-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(250px, 1fr);
    gap: 20px;
}


.room-info {
    align-self: start;
    overflow-wrap: anywhere;
}


.message {
    overflow-wrap: anywhere;
}


/* CHAT */

.chat-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 23px;
    background: linear-gradient(120deg, var(--surface), var(--soft-accent));
    border-bottom: 1px solid var(--border);
}


.chat-header-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: var(--accent);
    color: white;
    font-size: 22px;
}


.chat {
    display: flex;
    flex-direction: column;
    gap: 13px;
    height: 460px;
    min-height: 320px;
    max-height: 60vh;
    overflow-y: auto;
    padding: 24px 22px;
    background-color: var(--bg);
    border-radius: 0;
    scroll-behavior: smooth;
}


.chat-line {
    display: flex;
    width: 100%;
    animation: kuppiyaBubbleIn .32s ease both;
    flex-shrink: 0;
}


.chat-line-other {
    justify-content: flex-start;
}


.chat-line-mine {
    justify-content: flex-end;
}


.chat-line-system {
    justify-content: center;
}


.chat-bubble {
    max-width: min(76%, 490px);
    padding: 11px 15px 9px;
    border-radius: 19px;
    overflow-wrap: anywhere;
    box-shadow: 0 3px 12px rgba(0,0,0,.045);
}


.chat-bubble-other {
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border);
    border-bottom-left-radius: 5px;
}


.chat-bubble-mine {
    background: linear-gradient(135deg, #755df0, #6147d8);
    color: #ffffff;
    border-bottom-right-radius: 5px;
}


.chat-bubble-system {
    background: var(--soft-accent);
    border: 1px solid var(--border);
    color: var(--muted);
    text-align: center;
    max-width: 90%;
    font-size: 12px;
    border-radius: 999px;
    padding: 8px 17px;
}


.chat-author {
    font-size: 12px;
    font-weight: 800;
    color: var(--accent);
    margin-bottom: 3px;
}


.chat-text {
    white-space: pre-wrap;
    font-size: 14px;
    line-height: 1.55;
}


.chat-time {
    text-align: right;
    font-size: 10px;
    opacity: .7;
    margin-top: 5px;
}


.chat-compose {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
    padding: 16px 20px;
    background: var(--surface);
    border-top: 1px solid var(--border);
}


.chat-compose textarea {
    display: block;
    flex: 1;
    min-width: 0;
    height: 46px;
    min-height: 46px;
    max-height: 130px;
    padding: 12px 17px;
    border: 1px solid var(--border);
    border-radius: 24px;
    background: var(--bg);
    resize: none;
    overflow-y: hidden;
    line-height: 22px;
}


.chat-compose .chat-send {
    display: grid;
    place-items: center;
    flex: none;
    width: 46px;
    height: 46px;
    padding: 0;
    border-radius: 50%;
    font-size: 20px;
}


.chat-compose .chat-send svg {
    width: 20px;
    height: 20px;
    fill: currentColor;
}


.visually-hidden {
    position: absolute !important;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0,0,0,0);
    white-space: nowrap;
    border: 0;
}


.member-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}


.member {
    display: flex;
    align-items: center;
    gap: 10px;
}


.mini-avatar {
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--soft-accent);
    color: var(--accent);
    font-weight: 800;
}


@keyframes kuppiyaBubbleIn {
    from {
        opacity: 0;
        transform: translateY(9px) scale(.97);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}


@media(max-width:900px) {

    .layout {
        grid-template-columns: 1fr;
    }

    .sidebar {
        border-right: 0; border-bottom: 1px solid var(--border); padding: 18px; gap: 18px;
    }

    .brand {
        margin-bottom: 0; padding-left: 0;
    }

    .nav { display: flex; flex-wrap: wrap; }
    .sidebar-bottom { display: none; }

    .room-grid {
        grid-template-columns: 1fr;
    }

}


@media(max-width:600px) {

    main {
        padding: 20px 15px 50px;
    }

    .grid {
        grid-template-columns: 1fr;
    }

    .field.full {
        grid-column: auto;
    }

    .chat {
        height: 410px;
        max-height: 55vh;
        padding: 18px 12px;
    }

    .chat-bubble {
        max-width: 88%;
    }

    .chat-compose {
        padding: 12px;
        gap: 8px;
    }

    .chat-header {
        padding: 15px;
    }

    .room-heading {
        align-items: flex-start;
    }

}


@media(prefers-reduced-motion: reduce) {

    .chat-line {
        animation: none;
    }

    .chat {
        scroll-behavior: auto;
    }

}


/* Premium Kuppiya scheduler */
.kp-scheduler{min-width:0}.kp-schedule-heading,.kp-calendar-head,.kp-times-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.kp-schedule-heading{margin-bottom:18px}.kp-schedule-heading label{font-weight:750;font-size:16px}.kp-schedule-heading p{margin:4px 0 0}.kp-zone{font-size:11px;color:var(--muted);border:1px solid var(--border);padding:8px 10px;border-radius:999px;white-space:nowrap}.kp-schedule-layout{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:16px}.kp-calendar,.kp-times{border:1px solid var(--border);border-radius:18px;padding:17px;background:var(--surface);min-width:0}.kp-calendar-head{margin-bottom:15px}.kp-calendar-head strong,.kp-times-head strong{font-size:14px}.kp-nav{border:1px solid var(--border);background:var(--surface);color:var(--text);width:32px;height:32px;border-radius:10px;font-size:24px;line-height:1;cursor:pointer}.kp-nav:disabled{opacity:.3;cursor:not-allowed}.kp-day-names,.kp-calendar-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:5px;text-align:center}.kp-day-names{margin-bottom:7px}.kp-day-names span{font-size:11px;color:var(--muted);padding:7px 0}.kp-date{border:1px solid transparent;background:transparent;color:var(--text);border-radius:10px;min-height:39px;cursor:pointer;font:inherit;font-size:13px;transition:background .15s,border-color .15s}.kp-date:hover:not(:disabled),.kp-time:hover:not(:disabled){background:var(--soft-accent);border-color:var(--accent)}.kp-date:disabled,.kp-time:disabled{opacity:.25;cursor:not-allowed}.kp-date.kp-picked,.kp-time.kp-picked{background:var(--accent);color:#fff;border-color:var(--accent);font-weight:700}.kp-date.kp-today:not(.kp-picked){border-color:var(--accent)}.kp-times-head{align-items:start;margin-bottom:13px;flex-wrap:wrap}.kp-times-head .meta{font-size:11px}.kp-time-choices{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;max-height:270px;overflow-y:auto;align-content:start;padding-right:3px}.kp-time{border:1px solid var(--border);background:var(--surface);color:var(--text);border-radius:10px;padding:11px 3px;font:inherit;font-size:12px;cursor:pointer;transition:background .15s,border-color .15s}.kp-selection{margin-top:12px;border-radius:11px;background:var(--soft-accent);color:var(--accent);padding:11px 14px;font-size:12px;font-weight:650}@media(max-width:760px){.kp-schedule-layout{grid-template-columns:1fr}.kp-zone{display:none}.kp-calendar,.kp-times{padding:13px}.kp-time-choices{max-height:205px}}


/* Stage 7: refined session discovery */
.kp-toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:18px 0 22px}
.kp-search{flex:1;min-width:220px;position:relative}
.kp-search input{width:100%;padding:13px 16px 13px 42px;border:1px solid var(--border);border-radius:14px;background:var(--surface);color:var(--text);font:inherit;outline:none;transition:border-color .2s,box-shadow .2s}
.kp-search input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--soft-accent)}
.kp-search:before{content:'⌕';position:absolute;left:16px;top:6px;font-size:25px;color:var(--muted)}
.kp-filters{display:flex;gap:6px;flex-wrap:wrap}
.kp-filter{border:1px solid var(--border);background:var(--surface);color:var(--muted);padding:10px 14px;border-radius:12px;font:inherit;font-size:13px;cursor:pointer;transition:all .2s}
.kp-filter[aria-pressed="true"]{background:var(--soft-accent);color:var(--accent);border-color:var(--accent);font-weight:700}
.kp-session-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:16px}
.kp-session-card{border:1px solid var(--border);background:var(--surface);border-radius:20px;padding:22px;display:flex;flex-direction:column;gap:14px;min-width:0;transition:transform .2s,border-color .2s,box-shadow .2s}
.kp-session-card:hover{transform:translateY(-3px);border-color:var(--accent);box-shadow:0 12px 32px rgba(20,20,40,.07)}
.kp-session-top,.kp-session-bottom{display:flex;align-items:center;justify-content:space-between;gap:10px}
.kp-tag{font-size:11px;font-weight:750;letter-spacing:.02em;padding:5px 10px;border-radius:99px;background:var(--soft-accent);color:var(--accent)}
.kp-tag.neutral{background:var(--bg);color:var(--muted)}
.kp-session-card h3{font-size:18px;line-height:1.3;letter-spacing:-.35px;margin:0;overflow-wrap:anywhere}
.kp-session-details{display:grid;gap:8px;font-size:13px;color:var(--muted)}
.kp-session-details span{display:flex;align-items:center;gap:8px}
.kp-session-bottom{margin-top:auto;padding-top:16px;border-top:1px solid var(--border)}
.kp-session-bottom .btn{border-radius:11px;font-size:13px;padding:9px 13px}
.kp-empty-filter{padding:32px 20px;border:1px dashed var(--border);border-radius:16px;text-align:center;color:var(--muted)}
.kp-session-card[hidden],.kp-empty-filter[hidden]{display:none!important}
@media(max-width:600px){.kp-toolbar{align-items:stretch}.kp-search{min-width:100%}.kp-filters{width:100%}.kp-filter{flex:1}.kp-session-card{padding:18px}}

</style>


<!-- Kuppiya premium UI assets -->
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-community-phase4.css?v=1')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-kuppiya-premium.css?v=3-20261110')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-kuppiya-premium.js?v=3-20261110')) ?>"></script>
</head>


<body class="kp-v2">

<div class="layout">

<aside class="sidebar" id="studentSidebar">

<a class="brand" href="<?= e(url('dashboard/index.php')) ?>">
    <img class="kp-nsbm-logo" src="<?= e(url('assets/images/nsbm-shield-only.png')) ?>" alt="NSBM shield" width="42" height="47" decoding="async"><span class="kp-wordmark">uLearn<span>.</span></span>
</a>

<button type="button" class="kp-mobile-nav-toggle" id="kpMobileNavToggle" aria-expanded="false" aria-controls="kpSidebarNav">☰ <span>Workspace menu</span></button>
<div class="nav-label">YOUR WORKSPACE</div>
<nav class="nav" id="kpSidebarNav" aria-label="Student navigation">

    <div class="nav ul-nav">
        <a href="<?= e(url('dashboard/index.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg><span>Overview</span></a>
        <a href="<?= e(url('dashboard/index.php#modules')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14M3 18V4a1 1 0 0 1 1-1h5a3 3 0 0 1 3 3 3 3 0 0 1 3-3h5a1 1 0 0 1 1 1v14h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg><span>My Modules</span></a>
        <a href="<?= e(url('dashboard/explore.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m16 8-2 6-6 2 2-6z"/></svg><span>Explore & Enroll</span></a>
        <a href="<?= e(url('dashboard/notes-library.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="7" width="12" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h2"/></svg><span>Notes Library</span></a>
        <a href="<?= e(url('dashboard/notes.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V8m-4 4 4-4 4 4M4 17a5 5 0 0 1 1-9 7 7 0 0 1 13 0 5 5 0 0 1 2 9"/></svg><span>Upload Notes</span></a>
        <a href="<?= e(url('dashboard/quiz-history.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-7M3 3v6h6M12 7v5l3 2"/></svg><span>Quiz History</span></a>
        <a href="<?= e(url('dashboard/learning-insights.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 17 6-6 4 4 8-8M15 7h6v6"/></svg><span>Learning Insights</span></a>
        <a href="<?= e(url('dashboard/analytics.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V12h4v8M10 20V4h4v16M16 20v-9h4v9M2 20h20"/></svg><span>Performance Analytics</span></a>
        <a href="<?= e(url('dashboard/topic-analysis.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg><span>Topic Weaknesses</span></a>
        <a href="<?= e(url('dashboard/ai-study.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 17l.7 1.3L21 19l-1.3.7L19 21l-.7-1.3L17 19l1.3-.7z"/></svg><span>AI Study Assistant</span></a>
        <a href="<?= e(url('dashboard/consultations.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10l4-3h11a2 2 0 0 0 2-1zM9 16v3a2 2 0 0 0 2 2h8l3 2v-9"/></svg><span>Consultations</span></a>
        <a class="active" href="<?= e($base) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6M17 15a5 5 0 0 1 4 5"/></svg><span>Kuppiya Sessions</span></a>
        <a href="<?= e(url('dashboard/leaderboard.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h10v7a5 5 0 0 1-10 0zM7 6H4v3a4 4 0 0 0 4 4m9-7h3v3a4 4 0 0 1-4 4M12 16v4m-4 1h8"/></svg><span>Leaderboard</span></a>
        <a href="<?= e(url('dashboard/notifications.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Notifications</span></a>
        <a href="<?= e(url('dashboard/profile.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="2"/><circle cx="16" cy="17" r="2"/></svg><span>My Profile & Settings</span></a>
    </div>
</nav>

<div class="sidebar-bottom">
    <small>Signed in as</small>
    <strong><?= e((string)$user['full_name']) ?></strong>
    <small><?= e((string)($user['email'] ?? '')) ?></small>
    <small style="display:block;margin-top:12px"><?= $notePoints ?> contribution points</small>
</div>

</aside>


<main>

<header class="topbar">

<div class="kp-top-identity"><div class="eyebrow">ULEARN / STUDENT SPACE</div><?php if ($kpBatch !== ''): ?><span class="kp-batch">MY BATCH <?= e($kpBatch) ?></span><?php endif; ?></div>

<div class="right">

<a class="notification-bell" href="<?= e(url('dashboard/notifications.php')) ?>"
   aria-label="Notifications: <?= $unreadNotifications ?> unread"
   title="<?= $unreadNotifications ?> unread notifications">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
    <?php if ($unreadNotifications > 0): ?>
        <span class="notification-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
    <?php endif; ?>
</a>
<button id="themeButton" class="theme-button" type="button" aria-label="Toggle theme" title="Toggle theme">☾</button>

<a class="avatar"
   href="<?= e(url('dashboard/profile.php')) ?>"
   title="My Profile">
    <?= e($initial) ?>
</a>

<form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <button type="submit" class="logout">
        Log out ↗
    </button>
</form>

</div>

</header>


<div class="kp-hero"><div class="kp-hero-glow" aria-hidden="true"></div><div class="eyebrow">STUDY TOGETHER · PEER LEARNING</div>

<h1>Kuppiya Sessions<span class="accent">.</span></h1>

<p class="lead">
Create private peer study groups, invite friends and chat with your members.
Maximum 12 students per group.
</p><div class="kp-hero-highlights"><span>✦ Collaborative learning</span><span>◷ Flexible scheduling</span><span>♧ Private group chat</span></div></div>


<?php if ($notice): ?>

<div class="notice" role="status">
    <?= e($notice) ?>
</div>

<?php endif; ?>


<?php if ($error): ?>

<div class="notice error" role="alert">
    <?= e($error) ?>
</div>

<?php endif; ?>





<div class="tabs" role="navigation" aria-label="Kuppiya pages">

<a
    class="tab <?= !$sessionId && $view === 'discover' ? 'active' : '' ?>"
    href="<?= e($base . '?view=discover') ?>">
    Discover Kuppiyas
</a>


<a
    class="tab <?= !$sessionId && $view === 'my' ? 'active' : '' ?>"
    href="<?= e($base . '?view=my') ?>">

    My Kuppiyas

    <span class="pill"><?= count($mine) ?></span>

</a>


<?php if ($selected && kpMember($pdo, (int)$selected['id'], $uid)): ?>

<span class="tab active">Private Room</span>

<?php endif; ?>

</div>


<?php if ($selected):

    $isMember = kpMember($pdo, (int)$selected['id'], $uid);

    $canJoin = $selected['status'] === 'scheduled'
        && strtotime($selected['starts_at']) > time();

?>


<section class="section">


<?php if ($isMember): ?>


<a class="back" href="<?= e($base . '?view=my') ?>">
    ← Back to My Kuppiyas
</a>


<div class="room-heading">

<div>

<span class="eyebrow">MEMBERS-ONLY ROOM</span>

<h2 style="margin:8px 0">
    <?= e($selected['title']) ?>
</h2>

<p class="meta">
Hosted by <?= e($selected['host_name']) ?>
· <?= (int)$selected['member_count'] ?> / 12 members
</p>

</div>

<span class="pill">
<?= e(strtotime($selected['ends_at']) <= time() ? 'Finished' : ucfirst($selected['status'])) ?>
</span>

</div>


<div class="room-grid">


<div class="card">


<div class="chat-header">

<div class="chat-header-icon">✦</div>

<div>

<strong>Group chat</strong>

<div class="meta">🔒 Only group members</div>

</div>

</div>


<div id="kuppiya-chat"></div>
<div class="chat" id="roomChat" aria-label="Group messages">


<?php if (!$messages): ?>

<div class="empty">
No messages yet. Say hello to your group!
</div>


<?php else: ?>

<?php foreach ($messages as $msg): ?>

<?php

$isSystem = $msg['message_type'] === 'system';

$isMine = !$isSystem &&
    (int)$msg['sender_user_id'] === $uid;

?>


<div class="chat-line <?= $isSystem ? 'chat-line-system' : ($isMine ? 'chat-line-mine' : 'chat-line-other') ?>">


<div class="chat-bubble <?= $isSystem ? 'chat-bubble-system' : ($isMine ? 'chat-bubble-mine' : 'chat-bubble-other') ?>">


<?php if (!$isSystem && !$isMine): ?>

<div class="chat-author">
<?= e($msg['full_name'] ?: 'Former member') ?>
</div>

<?php endif; ?>


<div class="chat-text">
<?= e($msg['message_text']) ?>
</div>


<div class="chat-time">
<?= e(date('g:i A', strtotime($msg['created_at']))) ?>
</div>


</div>

</div>


<?php endforeach; ?>

<?php endif; ?>


</div>


<?php if (
    $selected['status'] === 'scheduled' &&
    strtotime($selected['ends_at']) > time()
): ?>


<form method="post" class="chat-compose">

<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

<input type="hidden" name="action" value="chat">

<input
    type="hidden"
    name="session_id"
    value="<?= (int)$selected['id'] ?>">

<label for="message" class="visually-hidden">
Your message
</label>

<textarea
    id="message"
    name="message"
    maxlength="2000"
    rows="1"
    required
    placeholder="Type a message..."></textarea>


<button
    class="btn primary chat-send"
    type="submit"
    aria-label="Send message"
    title="Send message">

<svg viewBox="0 0 24 24" aria-hidden="true">
<path d="M3.4 20.2 21 12 3.4 3.8l.1 6.6L15 12 3.5 13.6z"/>
</svg>

</button>

</form>


<?php else: ?>

<p class="notice">
This session has ended. The conversation is read-only.
</p>

<?php endif; ?>


</div>


<aside class="card room-info">


<h3>Session details</h3>


<p class="meta">

<strong>Starts</strong><br>

<?= e($selected['starts_at']) ?>

</p>


<p class="meta">

<strong>Ends</strong><br>

<?= e($selected['ends_at']) ?>

</p>


<p class="meta">

<strong>Meeting type</strong><br>

<?= e($selected['location_type'] === 'online' ? 'Online' : 'In person') ?>

</p>


<p class="meta">

<strong>Meeting link / place</strong><br>

<?= e($selected['meeting_details'] ?: 'Not provided') ?>

</p>


<?php if ($selected['description']): ?>

<p class="message">
<?= e($selected['description']) ?>
</p>

<?php endif; ?>


<h3 style="margin-top:22px">Members</h3>


<div class="member-list">

<?php foreach ($members as $member): ?>

<div class="member">

<span class="mini-avatar">
<?= e(strtoupper(substr((string)$member['full_name'], 0, 1))) ?>
</span>

<span>

<?= e($member['full_name']) ?>

<?= (int)$member['user_id'] === (int)$selected['host_user_id']
    ? ' · Host'
    : '' ?>

</span>

</div>

<?php endforeach; ?>

</div>


<h3 style="margin-top:22px">Invite your peers</h3>


<label class="meta" for="inviteLink">
Share this room's invitation
</label>


<input
    id="inviteLink"
    readonly
    value="<?= e(
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://'
        . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . url('dashboard/kuppiya.php?invite=' . $selected['invite_token'])
    ) ?>">


<p>

<button
    class="btn"
    type="button"
    onclick="copyInvite('inviteLink')">

Copy invitation link

</button>

</p>


<p class="meta">
Invitees must log in and join before they can access chat.
</p>


<?php if ($canJoin): ?>

<form method="post" onsubmit="return confirm('Leave this Kuppiya?')">

<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

<input type="hidden" name="action" value="leave">

<input
    type="hidden"
    name="session_id"
    value="<?= (int)$selected['id'] ?>">

<button class="btn" type="submit">
Leave Kuppiya
</button>

</form>

<?php endif; ?>


</aside>

</div>


<?php else: ?>


<a class="back" href="<?= e($base . '?view=discover') ?>">
← Back to Discover
</a>


<div class="card" style="margin-top:15px">

<span class="pill">
<?= e(ucfirst($selected['status'])) ?>
</span>


<h2 style="margin-top:15px">
<?= e($selected['title']) ?>
</h2>


<p class="meta">

Hosted by <?= e($selected['host_name']) ?>

· <?= (int)$selected['member_count'] ?> / 12 members

</p>


<p>

<strong>Schedule:</strong>

<?= e($selected['starts_at']) ?>

–

<?= e($selected['ends_at']) ?>

</p>


<?php if ($selected['description']): ?>

<p class="message">
<?= e($selected['description']) ?>
</p>

<?php endif; ?>


<p class="notice">
Join this group to access its private room, chat, members, and meeting details.
</p>


<?php if ($canJoin && (int)$selected['member_count'] < 12): ?>


<form method="post">

<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

<input type="hidden" name="action" value="join">

<input
    type="hidden"
    name="session_id"
    value="<?= (int)$selected['id'] ?>">

<button class="btn primary" type="submit">
Join Kuppiya →
</button>

</form>


<?php else: ?>

<p class="meta">
This session is not open for new members.
</p>

<?php endif; ?>


</div>


<?php endif; ?>


</section>


<?php elseif ($view === 'my'): ?>


<section class="section">

<h2>My Kuppiyas</h2>

<p class="lead">
Open a group you've joined to enter its private chat room.
</p>


<?php if (empty($mine)): ?>


<div class="empty">

You haven't joined a Kuppiya yet.

<a href="<?= e($base . '?view=discover') ?>">
Discover a session →
</a>

</div>


<?php else: ?>


<div class="kp-toolbar" data-kp-scope="mine">
  <div class="kp-search"><input type="search" data-kp-search placeholder="Search your sessions…" aria-label="Search my Kuppiyas"></div>
  <div class="kp-filters" aria-label="Filter my Kuppiyas">
    <button class="kp-filter" type="button" data-kp-filter="all" aria-pressed="true">All</button>
    <button class="kp-filter" type="button" data-kp-filter="upcoming" aria-pressed="false">Upcoming</button>
    <button class="kp-filter" type="button" data-kp-filter="completed" aria-pressed="false">Completed</button>
    <button class="kp-filter" type="button" data-kp-filter="cancelled" aria-pressed="false">Cancelled</button>
  </div>
</div>
<div class="kp-session-grid" data-kp-cards>
<?php foreach ($mine as $s):
    $mineState = $s['status'] === 'cancelled' ? 'cancelled' : (($s['status'] === 'completed' || strtotime($s['ends_at']) <= time()) ? 'completed' : 'upcoming');
?>
<article class="kp-session-card" data-kp-card data-state="<?= e($mineState) ?>" data-search="<?= e(strtolower($s['title'])) ?>">
  <div class="kp-session-top"><span class="kp-tag"><?= e(ucfirst($mineState)) ?></span><span class="kp-tag neutral">My session</span></div>
  <h3><?= e($s['title']) ?></h3>
  <div class="kp-session-details"><span>◷ <?= e(date('D, M j · g:i A', strtotime($s['starts_at']))) ?></span></div>
  <div class="kp-session-bottom"><span class="meta">Session details & chat</span><a class="btn primary" href="<?= e(url('dashboard/kuppiya-room.php?id=' . (int)$s['id'])) ?>">Open →</a></div>
</article>
<?php endforeach; ?>
</div>
<div class="kp-empty-filter" data-kp-empty hidden>No sessions match this filter.</div>


<?php endif; ?>


</section>


<?php else: ?>


<section class="section">

<h2>Upcoming Kuppiyas</h2>

<p class="lead">
Find a group, join it, then open its chat from My Kuppiyas.
</p>


<?php if (empty($upcoming)): ?>


<div class="empty">
No upcoming sessions yet. Create the first one!
</div>


<?php else: ?>


<div class="kp-toolbar" data-kp-scope="discover">
  <div class="kp-search"><input type="search" data-kp-search placeholder="Search Kuppiyas or hosts…" aria-label="Search upcoming Kuppiyas"></div>
  <div class="kp-filters" aria-label="Filter upcoming Kuppiyas">
    <button class="kp-filter" type="button" data-kp-filter="all" aria-pressed="true">All sessions</button>
    <button class="kp-filter" type="button" data-kp-filter="joined" aria-pressed="false">Joined</button>
    <button class="kp-filter" type="button" data-kp-filter="available" aria-pressed="false">Available</button>
  </div>
</div>
<div class="kp-session-grid" data-kp-cards>
<?php foreach ($upcoming as $s):
    $joined = (int)$s['is_member'] === 1;
    $full = (int)$s['member_count'] >= 12;
?>
<article class="kp-session-card" data-kp-card data-state="<?= $joined ? 'joined' : 'available' ?>" data-search="<?= e(strtolower($s['title'].' '.$s['host_name'])) ?>">
  <div class="kp-session-top"><span class="kp-tag"><?= $joined ? 'Joined' : ($full ? 'Full' : 'Open to join') ?></span><span class="kp-tag neutral"><?= (int)$s['member_count'] ?> / 12 seats</span></div>
  <h3><?= e($s['title']) ?></h3>
  <div class="kp-session-details">
    <span>◷ <?= e(date('D, M j · g:i A', strtotime($s['starts_at']))) ?></span>
    <span>⌁ <?= e(date('g:i A', strtotime($s['ends_at']))) ?> end</span>
    <span>♙ Hosted by <?= e($s['host_name']) ?></span>
  </div>
  <div class="kp-session-bottom"><span class="meta"><?= $joined ? 'Your study group' : ($full ? 'All seats taken' : 'Peer study session') ?></span>
    <a class="btn <?= $joined ? 'primary' : '' ?>" href="<?= e(($joined ? url('dashboard/kuppiya-room.php?id=' . (int)$s['id']) : $base . '?session=' . (int)$s['id'])) ?>"><?= $joined ? 'Open room →' : 'View details →' ?></a>
  </div>
</article>
<?php endforeach; ?>
</div>
<div class="kp-empty-filter" data-kp-empty hidden>No sessions match your search.</div>


<?php endif; ?>


</section>


<?php if (!$sessionId && $view === 'discover'): ?>

<section class="section">

<h2>Create a Kuppiya</h2>

<div class="card">

<form method="post">

<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

<input type="hidden" name="action" value="create">

<div class="grid">


<div class="field full">

<label for="title">What are we studying?</label>

<input
    id="title"
    name="title"
    maxlength="180"
    required
    placeholder="e.g. Algorithm Sorting Revision">

</div>


<div class="field full kp-scheduler">
  <div class="kp-schedule-heading"><div><label>When is your Kuppiya?</label><p class="meta">Pick a date, then a start time.</p></div><span class="kp-zone">Sri Lanka time · GMT+5:30</span></div>
  <div class="kp-schedule-layout">
    <div class="kp-calendar" aria-label="Session calendar">
      <div class="kp-calendar-head"><button type="button" id="kpPrevMonth" class="kp-nav" aria-label="Previous month">‹</button><strong id="kpMonthTitle"></strong><button type="button" id="kpNextMonth" class="kp-nav" aria-label="Next month">›</button></div>
      <div class="kp-day-names"><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span></div>
      <div id="kpCalendarDays" class="kp-calendar-days" role="group" aria-label="Choose date"></div>
    </div>
    <div class="kp-times"><div class="kp-times-head"><strong>Start time</strong><span id="kpDateSummary" class="meta">Select a date</span></div><div id="kpTimeChoices" class="kp-time-choices" role="group" aria-label="Choose start time"></div></div>
  </div>
  <div class="kp-selection" id="kpSelection" aria-live="polite">Choose your date and time</div>
  <input id="session_date" name="session_date" type="hidden" required>
  <input id="start_time" name="start_time" type="hidden" required>
</div>


<div class="field">

<label for="duration_minutes">How long?</label>

<select id="duration_minutes" name="duration_minutes" required>

<option value="30">30 minutes</option>
<option value="60" selected>1 hour</option>
<option value="90">1 hour 30 minutes</option>
<option value="120">2 hours</option>
<option value="180">3 hours</option>

</select>

<span class="meta">End time is calculated automatically.</span>

</div>


<div class="field">

<label for="location_type">Meeting type</label>

<select id="location_type" name="location_type">

<option value="online">Online</option>
<option value="in_person">In person</option>

</select>

</div>


<div class="field full">

<label for="meeting_details">Meeting link or location (members only)</label>

<input
    id="meeting_details"
    name="meeting_details"
    maxlength="500"
    placeholder="Zoom link or study room">

</div>


<div class="field full">

<label for="description">Description (optional)</label>

<textarea
    id="description"
    name="description"
    maxlength="3000"
    placeholder="Topics we'll cover"></textarea>

</div>

</div>


<p>
<button class="btn primary" type="submit">
    Create Kuppiya →
</button>
</p>

<div class="meta">
The host counts as member 1. At least 2 members are needed when the session begins.
No module selection needed.
</div>

</form>

</div>

</section>

<?php endif; ?>


<?php endif; ?>


<p class="meta" style="margin-top:35px">

Sessions are archived after their scheduled end.
Only members can access group chat and meeting details.

</p>


</main>

</div>


<script>

// Kuppiya date/time picker. Hidden inputs retain the existing PHP POST contract.
document.addEventListener('DOMContentLoaded', function () {
    const dateInput = document.getElementById('session_date');
    const timeInput = document.getElementById('start_time');
    if (!dateInput || !timeInput) return;
    const daysEl = document.getElementById('kpCalendarDays');
    const timesEl = document.getElementById('kpTimeChoices');
    const titleEl = document.getElementById('kpMonthTitle');
    const summaryEl = document.getElementById('kpDateSummary');
    const selectionEl = document.getElementById('kpSelection');
    const prev = document.getElementById('kpPrevMonth');
    const next = document.getElementById('kpNextMonth');
    const formatDate = d => [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-');
    const today = new Date(); today.setHours(0,0,0,0);
    let month = new Date(today.getFullYear(),today.getMonth(),1);
    let chosenDate = ''; let chosenTime = '';
    const pretty = d => d.toLocaleDateString('en-LK',{weekday:'short',month:'short',day:'numeric',year:'numeric'});
    function renderTimes(){
        timesEl.replaceChildren();
        if (!chosenDate){summaryEl.textContent='Select a date';return;}
        const parts=chosenDate.split('-').map(Number);
        const chosen=new Date(parts[0],parts[1]-1,parts[2]);
        summaryEl.textContent=pretty(chosen);
        for(let h=7;h<=22;h++)for(let m=0;m<60;m+=30){
            const value=String(h).padStart(2,'0')+':'+String(m).padStart(2,'0');
            const label=(h%12||12)+':'+String(m).padStart(2,'0')+' '+(h<12?'AM':'PM');
            const btn=document.createElement('button');btn.type='button';btn.className='kp-time'+(value===chosenTime?' kp-picked':'');btn.textContent=label;
            // Only enable future times on the current date.
            const now=new Date();
            if(formatDate(chosen)===formatDate(now) && (h*60+m)<=now.getHours()*60+now.getMinutes())btn.disabled=true;
            btn.setAttribute('aria-pressed',String(value===chosenTime));
            btn.addEventListener('click',()=>{chosenTime=value;timeInput.value=value;renderTimes();selectionEl.textContent=pretty(chosen)+' · '+label;});
            timesEl.append(btn);
        }
    }
    function renderCalendar(){
        daysEl.replaceChildren();titleEl.textContent=month.toLocaleDateString('en-LK',{month:'long',year:'numeric'});
        prev.disabled=month.getFullYear()===today.getFullYear()&&month.getMonth()===today.getMonth();
        const first=(month.getDay()+6)%7;
        for(let i=0;i<first;i++){const empty=document.createElement('span');daysEl.append(empty);}
        const count=new Date(month.getFullYear(),month.getMonth()+1,0).getDate();
        for(let day=1;day<=count;day++){
            const d=new Date(month.getFullYear(),month.getMonth(),day);const value=formatDate(d);
            const btn=document.createElement('button');btn.type='button';btn.className='kp-date'+(value===chosenDate?' kp-picked':'')+(value===formatDate(today)?' kp-today':'');btn.textContent=String(day);btn.disabled=d<today;
            btn.setAttribute('aria-label',pretty(d));btn.setAttribute('aria-pressed',String(value===chosenDate));
            btn.addEventListener('click',()=>{chosenDate=value;chosenTime='';dateInput.value=value;timeInput.value='';selectionEl.textContent='Choose a start time';renderCalendar();renderTimes();});
            daysEl.append(btn);
        }
    }
    prev.addEventListener('click',()=>{month=new Date(month.getFullYear(),month.getMonth()-1,1);renderCalendar();});
    next.addEventListener('click',()=>{month=new Date(month.getFullYear(),month.getMonth()+1,1);renderCalendar();});
    const form=dateInput.closest('form');
    form.addEventListener('submit',e=>{if(!dateInput.value||!timeInput.value){e.preventDefault();selectionEl.textContent='Please choose both a date and a start time.';selectionEl.scrollIntoView({behavior:'smooth',block:'center'});}});
    renderCalendar();renderTimes();
});

</script>

<!-- CHAT JAVASCRIPT -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    // Automatically scroll chat to the latest message.

    var chat = document.getElementById('roomChat');

    if (chat) {
        chat.scrollTop = chat.scrollHeight;
        if (window.location.hash === '#kuppiya-chat') {
            requestAnimationFrame(function () {
                document.getElementById('kuppiya-chat').scrollIntoView({block: 'start'});
            });
        }
    }


    // Chat message textarea.

    var field = document.getElementById('message');

    if (!field) return;


    function resize() {

        field.style.height = '46px';

        field.style.height =
            Math.min(field.scrollHeight, 130) + 'px';

        field.style.overflowY =
            field.scrollHeight > 130 ? 'auto' : 'hidden';

    }


    field.form.addEventListener('submit', function () {
        if (field.form.dataset.sending === 'submitted') return;
        field.form.dataset.sending = 'submitted';
        var send = field.form.querySelector('button[type="submit"]');
        if (send) { send.disabled = true; send.textContent = 'Sending…'; }
    });

    field.addEventListener('input', resize);

    resize();


    // Press Enter to send a message.

    field.addEventListener('keydown', function (e) {

        if (
            e.key === 'Enter' &&
            !e.shiftKey &&
            !e.isComposing
        ) {

            e.preventDefault();

            if (field.value.trim()) {
                if (!field.form.dataset.sending) {
                    field.form.dataset.sending = '1';
                    field.form.requestSubmit();
                }
            }

        }

    });

});

</script>



<script>
// Local filtering only: no additional database requests or session changes.
document.querySelectorAll('[data-kp-scope]').forEach(function(toolbar){
  var scope = toolbar.parentElement;
  var search = toolbar.querySelector('[data-kp-search]');
  var filters = Array.from(toolbar.querySelectorAll('[data-kp-filter]'));
  var cards = Array.from(scope.querySelectorAll('[data-kp-card]'));
  var empty = scope.querySelector('[data-kp-empty]');
  var active = 'all';
  function refresh(){
    var query = (search.value || '').trim().toLocaleLowerCase();
    var visible = 0;
    cards.forEach(function(card){
      var match = (active === 'all' || card.dataset.state === active) && (!query || (card.dataset.search || '').includes(query));
      card.hidden = !match;
      if(match) visible++;
    });
    if(empty) empty.hidden = visible !== 0;
  }
  filters.forEach(function(button){button.addEventListener('click',function(){
    active = button.dataset.kpFilter;
    filters.forEach(function(b){b.setAttribute('aria-pressed',String(b === button));});
    refresh();
  });});
  search.addEventListener('input',refresh);
  refresh();
});
</script>

<script src="<?= e(url('assets/js/notifications-live.js?v=20261107-profile-only-2')) ?>" data-base="<?= e(rtrim(url(), '/')) ?>" defer></script>
<?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body>
</html>