<?php
// api/admin/auth.php — Mobile Login Step 1 & Step 2 Dual 2FA REST API
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/../../includes/totp.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = sanitize($input['action'] ?? $_GET['action'] ?? '');

// ── STEP 1: Verify Email & Password ─────────────────────────────────────────
if ($action === 'login_step1') {
    $email = sanitize($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        json_response(['status' => 'error', 'message' => 'Please provide both email and password.'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Generate temporary session token for Step 2
        $temp_token = bin2hex(random_bytes(24));
        $has_totp = !empty($user['totp_enabled']);
        $preferred_2fa = $has_totp && ($user['preferred_2fa'] ?? '') === 'totp' ? 'totp' : 'email';

        // Save temp session state in database or session
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 mins
        $pdo->prepare("DELETE FROM api_tokens WHERE user_id = ? AND device_name LIKE 'temp_%'")->execute([$user['id']]);
        
        $temp_hash = hash('sha256', $temp_token);
        $stmt_tmp = $pdo->prepare("INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at) VALUES (?, ?, ?, ?)");
        $stmt_tmp->execute([$user['id'], $temp_hash, "temp_{$preferred_2fa}", $expires]);

        // If Email OTP preferred, generate and dispatch email
        if ($preferred_2fa === 'email') {
            $otp_code = (string)rand(100000, 999999);
            // Save OTP code in temp token device name metadata
            $pdo->prepare("UPDATE api_tokens SET device_name = ? WHERE token_hash = ?")->execute(["temp_email_{$otp_code}", $temp_hash]);

            $subject = "Admin Verification Security Code: Time to Shine Mobile App";
            $body = "
                <h3 style='color: #ea580c;'>Administrative Security Verification</h3>
                <p>Hello <strong>" . htmlspecialchars($user['name']) . "</strong>,</p>
                <p>Your 2-Step Verification security code for the Mobile Admin App is:</p>
                <div style='background: #fffaf8; border: 1.5px dashed #f97316; padding: 18px; text-align: center; border-radius: 10px; margin: 20px 0;'>
                    <span style='font-size: 30px; font-weight: 900; color: #ea580c; letter-spacing: 5px; font-family: monospace;'>" . htmlspecialchars($otp_code) . "</span>
                </div>
            ";
            spool_mail($user['email'], $subject, $body);
        }

        json_response([
            'status'             => '2fa_required',
            'temp_session_token' => $temp_token,
            'preferred_2fa'      => $preferred_2fa,
            'has_totp'           => $has_totp,
            'user' => [
                'name'  => $user['name'],
                'email' => $user['email']
            ]
        ]);

    } else {
        json_response(['status' => 'error', 'message' => 'Invalid administrative email or password credentials.'], 401);
    }
}

// ── STEP 2: Verify 2FA Code & Issue Bearer Token ────────────────────────────
elseif ($action === 'login_step2') {
    $temp_token = sanitize($input['temp_session_token'] ?? '');
    $method = sanitize($input['method'] ?? 'email');
    $code = trim($input['code'] ?? '');

    if (empty($temp_token) || empty($code)) {
        json_response(['status' => 'error', 'message' => 'Missing session token or 6-digit verification code.'], 400);
    }

    $temp_hash = hash('sha256', $temp_token);
    $stmt = $pdo->prepare("
        SELECT t.id as token_id, t.device_name, u.*
        FROM api_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.expires_at > CURRENT_TIMESTAMP AND t.device_name LIKE 'temp_%'
    ");
    $stmt->execute([$temp_hash]);
    $temp_record = $stmt->fetch();

    if (!$temp_record) {
        json_response(['status' => 'error', 'message' => 'Temporary session expired. Please log in again.'], 401);
    }

    $auth_success = false;

    if ($method === 'totp' && !empty($temp_record['totp_enabled'])) {
        if (totp_verify_code($temp_record['totp_secret'], $code)) {
            $auth_success = true;
        } else {
            json_response(['status' => 'error', 'message' => 'Invalid Google Authenticator 6-digit code. Please check your app.'], 400);
        }
    } else {
        // Email OTP check
        if (preg_match('/temp_email_(\d{6})/', $temp_record['device_name'], $m)) {
            $stored_otp = $m[1];
            if (hash_equals($stored_otp, $code)) {
                $auth_success = true;
            } else {
                json_response(['status' => 'error', 'message' => 'Incorrect Email OTP security code.'], 400);
            }
        } else {
            json_response(['status' => 'error', 'message' => 'Email OTP not initialized. Please request a new code.'], 400);
        }
    }

    if ($auth_success) {
        // Delete temp token & issue long-lived Bearer Access Token
        $pdo->prepare("DELETE FROM api_tokens WHERE id = ?")->execute([$temp_record['token_id']]);
        $access_token = generate_api_token($pdo, $temp_record['id'], "Android App (" . ($_SERVER['HTTP_USER_AGENT'] ?? 'Mobile') . ")");

        json_response([
            'status'       => 'success',
            'access_token' => $access_token,
            'expires_in'   => 30 * 86400,
            'user' => [
                'id'    => (int)$temp_record['id'],
                'name'  => $temp_record['name'],
                'email' => $temp_record['email'],
                'role'  => $temp_record['role']
            ]
        ]);
    }
}

// ── Switch 2FA Method During Login ───────────────────────────────────────────
elseif ($action === 'switch_2fa') {
    $temp_token = sanitize($input['temp_session_token'] ?? '');
    $target_method = sanitize($input['target_method'] ?? 'email');

    if (empty($temp_token)) {
        json_response(['status' => 'error', 'message' => 'Missing session token.'], 400);
    }

    $temp_hash = hash('sha256', $temp_token);
    $stmt = $pdo->prepare("
        SELECT t.id as token_id, u.*
        FROM api_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.expires_at > CURRENT_TIMESTAMP AND t.device_name LIKE 'temp_%'
    ");
    $stmt->execute([$temp_hash]);
    $user = $stmt->fetch();

    if (!$user) {
        json_response(['status' => 'error', 'message' => 'Session expired. Please log in again.'], 401);
    }

    if ($target_method === 'email') {
        $otp_code = (string)rand(100000, 999999);
        $pdo->prepare("UPDATE api_tokens SET device_name = ? WHERE token_hash = ?")->execute(["temp_email_{$otp_code}", $temp_hash]);

        $subject = "Admin Verification Security Code: Time to Shine Mobile App";
        $body = "
            <h3 style='color: #ea580c;'>Administrative Security Verification</h3>
            <p>Hello <strong>" . htmlspecialchars($user['name']) . "</strong>,</p>
            <p>Your 2-Step Verification security code is: <strong>{$otp_code}</strong></p>
        ";
        spool_mail($user['email'], $subject, $body);

        json_response(['status' => 'success', 'message' => 'Email OTP code dispatched.', 'current_method' => 'email']);
    } else {
        if (empty($user['totp_enabled'])) {
            json_response(['status' => 'error', 'message' => 'Google Authenticator is not paired on this account.'], 400);
        }
        $pdo->prepare("UPDATE api_tokens SET device_name = 'temp_totp' WHERE token_hash = ?")->execute([$temp_hash]);
        json_response(['status' => 'success', 'message' => 'Switched to Google Authenticator.', 'current_method' => 'totp']);
    }
}

// ── Logout ──────────────────────────────────────────────────────────────────
elseif ($action === 'logout') {
    $user = verify_mobile_token($pdo);
    $pdo->prepare("DELETE FROM api_tokens WHERE id = ?")->execute([$user['token_id']]);
    json_response(['status' => 'success', 'message' => 'Logged out successfully.']);
}

else {
    json_response(['status' => 'error', 'message' => 'Invalid action parameter.'], 400);
}
