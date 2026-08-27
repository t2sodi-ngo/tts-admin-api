<?php
// api/admin/dashboard.php — Mobile Dashboard Metrics REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);

// 1. Total Raised Amount
$stmt_raised = $pdo->query("SELECT SUM(amount) FROM donations WHERE status = 'captured'");
$total_raised = (float)($stmt_raised->fetchColumn() ?: 0.00);

// 2. Pending Donations Proof Audit Count
$stmt_pending = $pdo->query("SELECT COUNT(*) FROM donations WHERE status = 'pending'");
$pending_audits = (int)($stmt_pending->fetchColumn() ?: 0);

// 3. Active Volunteers Count
$stmt_vols = $pdo->query("SELECT COUNT(*) FROM volunteers WHERE status = 'approved'");
$active_volunteers = (int)($stmt_vols->fetchColumn() ?: 0);

// 4. Seva Events Count
$stmt_events = $pdo->query("SELECT COUNT(*) FROM events");
$total_events = (int)($stmt_events->fetchColumn() ?: 0);

// 5. Active Subscribers Count
$stmt_subs = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'");
$active_subscribers = (int)($stmt_subs->fetchColumn() ?: 0);

// 6. Recent 5 Pending Donations for Quick Audit
$stmt_rec = $pdo->query("SELECT id, receipt_no, donor_name, amount, payment_mode, transaction_id, purpose, created_at FROM donations WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
$recent_pending = $stmt_rec->fetchAll();

// 7. Recent System Activity Logs
$recent_logs = [];
try {
    $stmt_logs = $pdo->query("SELECT id, action, details, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 5");
    if ($stmt_logs) {
        $recent_logs = $stmt_logs->fetchAll();
    }
} catch (Exception $e) {
    // If activity_logs table is not present, return empty list safely
    $recent_logs = [];
}

json_response([
    'status' => 'success',
    'metrics' => [
        'total_raised'      => $total_raised,
        'pending_audits'    => $pending_audits,
        'active_volunteers' => $active_volunteers,
        'total_events'      => $total_events,
        'active_subscribers' => $active_subscribers
    ],
    'recent_pending_donations' => $recent_pending,
    'recent_activity_logs'     => $recent_logs,
    'user' => [
        'name' => $user['name'],
        'role' => $user['role']
    ]
]);
