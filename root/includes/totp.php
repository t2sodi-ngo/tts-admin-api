<?php
// includes/totp.php — Native PHP RFC 6238 TOTP (Google Authenticator) Library

/**
 * Base32 Alphabet lookup map (RFC 4648)
 */
define('TOTP_BASE32_CHARS', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567');

/**
 * Generate a random 16-character Base32 TOTP secret key
 *
 * @param int $secret_length Length of Base32 string (default 16 chars = 80 bits)
 * @return string
 */
function totp_generate_secret(int $secret_length = 16): string {
    $valid_chars = TOTP_BASE32_CHARS;
    $secret = '';
    $rnd_bytes = random_bytes($secret_length);
    for ($i = 0; $i < $secret_length; $i++) {
        $secret .= $valid_chars[ord($rnd_bytes[$i]) % 32];
    }
    return $secret;
}

/**
 * Decode a Base32 string to binary data
 *
 * @param string $base32
 * @return string|false
 */
function totp_base32_decode(string $base32) {
    $base32 = strtoupper($base32);
    $base32 = str_replace('=', '', $base32);
    $chars = TOTP_BASE32_CHARS;
    $buffer = 0;
    $buffer_size = 0;
    $binary = '';

    for ($i = 0; $i < strlen($base32); $i++) {
        $char = $base32[$i];
        $val = strpos($chars, $char);
        if ($val === false) {
            return false;
        }

        $buffer = ($buffer << 5) | $val;
        $buffer_size += 5;

        if ($buffer_size >= 8) {
            $buffer_size -= 8;
            $binary .= chr(($buffer >> $buffer_size) & 0xFF);
        }
    }

    return $binary;
}

/**
 * Calculate the 6-digit TOTP code for a given secret and timestamp
 *
 * @param string $secret Base32 secret string
 * @param int|null $time_slice Unix timestamp / 30
 * @return string|false 6-digit OTP code
 */
function totp_calculate_code(string $secret, ?int $time_slice = null) {
    if ($time_slice === null) {
        $time_slice = floor(time() / 30);
    }

    $secret_bytes = totp_base32_decode($secret);
    if ($secret_bytes === false) {
        return false;
    }

    // Pack time slice into 8-byte big-endian binary string
    $time_bytes = pack('N*', 0) . pack('N*', $time_slice);

    // Compute HMAC-SHA1
    $hash = hash_hmac('sha1', $time_bytes, $secret_bytes, true);

    // Dynamic Truncation
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

    $otp = $binary % 1000000;
    return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
}

/**
 * Verify a 6-digit TOTP user code against secret with clock drift tolerance
 *
 * @param string $secret Base32 secret key
 * @param string $user_code 6-digit input string
 * @param int $discrepancy Allowed 30s window drift (default 1 = ±30 seconds)
 * @return bool
 */
function totp_verify_code(string $secret, string $user_code, int $discrepancy = 1): bool {
    $user_code = trim($user_code);
    if (!preg_match('/^\d{6}$/', $user_code)) {
        return false;
    }

    $current_time_slice = floor(time() / 30);

    for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
        $calculated = totp_calculate_code($secret, $current_time_slice + $i);
        if ($calculated !== false && hash_equals($calculated, $user_code)) {
            return true;
        }
    }

    return false;
}

/**
 * Build standard Google Authenticator QR Code URL
 *
 * @param string $email User account email
 * @param string $secret Base32 secret key
 * @param string $issuer Issuer label (default: Time to Shine NGO)
 * @return string Image URL for QR Code
 */
function totp_get_qr_url(string $email, string $secret, string $issuer = 'Time to Shine NGO'): string {
    $otpauth_url = "otpauth://totp/" . rawurlencode($issuer) . ":" . rawurlencode($email) . "?secret=" . rawurlencode($secret) . "&issuer=" . rawurlencode($issuer);
    return "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . urlencode($otpauth_url);
}
