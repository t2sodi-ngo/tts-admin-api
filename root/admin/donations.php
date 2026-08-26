<?php
// api/admin/donations.php — Mobile Donations Audit & 80G Approval REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Donations List ────────────────────────────────────────────────
if ($method === 'GET') {
    $status = sanitize($_GET['status'] ?? 'pending');
    $search = sanitize($_GET['search'] ?? '');

    $sql = "SELECT * FROM donations WHERE 1=1";
    $params = [];

    if (!empty($status) && $status !== 'all') {
        $sql .= " AND status = ?";
        $params[] = $status;
    }

    if (!empty($search)) {
        $sql .= " AND (donor_name LIKE ? OR receipt_no LIKE ? OR transaction_id LIKE ? OR donor_email LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $donations = $stmt->fetchAll();

    json_response(['status' => 'success', 'count' => count($donations), 'donations' => $donations]);
}

// ── POST: Approve or Reject Donation ─────────────────────────────────────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'treasurer'], $user);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? '');
    $donation_id = (int)($input['donation_id'] ?? 0);

    if ($donation_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid donation ID.'], 400);
    }

    $stmt_find = $pdo->prepare("SELECT * FROM donations WHERE id = ?");
    $stmt_find->execute([$donation_id]);
    $donation = $stmt_find->fetch();

    if (!$donation) {
        json_response(['status' => 'error', 'message' => 'Donation record not found.'], 440);
    }

    if ($action === 'approve') {
        // Update status to captured
        $stmt_up = $pdo->prepare("UPDATE donations SET status = 'captured' WHERE id = ?");
        $stmt_up->execute([$donation_id]);

        // Auto-generate receipt PDF & send email
        $pdf_res = generate_donation_receipt_pdf($donation['receipt_no']);
        $pdf_file = $pdf_res['pdf_path'] ?? null;

        $subject = "Official Donation Receipt: {$donation['receipt_no']} - Time to Shine NGO";
        $body = "
            <h3 style='color: #ea580c;'>Thank You for Your Generous Contribution!</h3>
            <p>Dear <strong>" . htmlspecialchars($donation['donor_name']) . "</strong>,</p>
            <p>Your donation of <strong>₹" . number_format($donation['amount'], 2) . "</strong> has been verified and confirmed.</p>
            <p>Receipt No: <strong>" . htmlspecialchars($donation['receipt_no']) . "</strong></p>
            <p>Transaction ID: <strong>" . htmlspecialchars($donation['transaction_id']) . "</strong></p>
            <p>Donations to Time to Shine Social Charity Trust are eligible for 80G Tax Exemption.</p>
        ";
        spool_mail($donation['donor_email'], $subject, $body, $pdf_file);

        json_response(['status' => 'success', 'message' => "Donation {$donation['receipt_no']} approved and 80G receipt dispatched."]);
    }

    elseif ($action === 'reject') {
        $reason = sanitize($input['reason'] ?? 'Proof screenshot or transaction reference could not be verified.');
        $stmt_up = $pdo->prepare("UPDATE donations SET status = 'rejected' WHERE id = ?");
        $stmt_up->execute([$donation_id]);

        $subject = "Update regarding your donation submission: Time to Shine NGO";
        $body = "
            <p>Dear <strong>" . htmlspecialchars($donation['donor_name']) . "</strong>,</p>
            <p>We were unable to verify your donation reference <strong>" . htmlspecialchars($donation['transaction_id']) . "</strong>.</p>
            <p><strong>Note from Accounts Team:</strong> " . htmlspecialchars($reason) . "</p>
        ";
        spool_mail($donation['donor_email'], $subject, $body);

        json_response(['status' => 'success', 'message' => "Donation {$donation['receipt_no']} marked as rejected."]);
    }

    else {
        json_response(['status' => 'error', 'message' => 'Invalid action parameter.'], 400);
    }
}
