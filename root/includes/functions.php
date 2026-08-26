<?php
// includes/functions.php
require_once __DIR__ . '/db.php';

// Helper to get system settings
function get_setting($key, $default = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

// Helper to sanitize inputs
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Log an admin action to the audit_log table
function log_action(string $action, string $details = '') {
    global $pdo;
    $user = $_SESSION['admin_user'] ?? ['name' => 'System', 'role' => 'system'];
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')[0]);
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_log (admin_name, admin_role, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user['name'], $user['role'], $action, $details, $ip]);
    } catch (PDOException $e) {
        // Silent fail — don't break page flow for logging errors
    }
}

// Convert amount to words (Rupees)
function amount_in_words($number) {
    $decimal = round($number - ($no = floor($number)), 2) * 100;
    $hundred = null;
    $digits_length = strlen($no);
    $i = 0;
    $str = array();
    $words = array(
        0 => '', 1 => 'One', 2 => 'Two',
        3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six',
        7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve',
        13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
        16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
        19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty',
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty',
        70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
    );
    $digits = array('', 'Hundred','Thousand','Lakh', 'Crore');
    while( $i < $digits_length ) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += $divider == 10 ? 1 : 2;
        if ($number) {
            $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
            $str [] = ($number < 21) ? $words[$number].' '. $digits[$counter]. $plural.' '.$hundred:$words[floor($number / 10) * 10].' '.$words[$number % 10]. ' '.$digits[$counter].$plural.' '.$hundred;
        } else $str[] = null;
    }
    $Rupees = implode('', array_reverse($str));
    $paise = ($decimal > 0) ? "." . ($words[$decimal / 10] . " " . $words[$decimal % 10]) . ' Paise' : '';
    return ($Rupees ? $Rupees . 'Rupees ' : '') . $paise . ' Only';
}

// Real-time SMTP Socket Mailer Client
function send_smtp_socket_mail($to, $subject, $body, $headers = [], $attachments = []) {
    global $pdo;
    $smtp = [
        'host' => '',
        'port' => '',
        'user' => '',
        'pass' => '',
        'auth' => '1',
        'from' => 't2sodi@gmail.com',
        'from_name' => 'Time to Shine NGO'
    ];
    
    try {
        if (isset($pdo)) {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
            while ($row = $stmt->fetch()) {
                $smtp[str_replace('smtp_', '', $row['setting_key'])] = $row['setting_value'];
            }
        }
    } catch (Exception $e) {
        // Fallback
    }

    if (empty($smtp['host']) || empty($smtp['port']) || empty($smtp['user']) || empty($smtp['pass'])) {
        return false;
    }

    $host = $smtp['host'];
    $port = (int)$smtp['port'];
    $user = $smtp['user'];
    $pass = $smtp['pass'];
    $from = !empty($smtp['from']) ? $smtp['from'] : 't2sodi@gmail.com';
    $from_name = !empty($smtp['from_name']) ? $smtp['from_name'] : 'Time to Shine NGO';

    // Auto-prefix ssl:// for port 465 if not present
    if ($port === 465 && strpos($host, '://') === false) {
        $host = 'ssl://' . $host;
    }

    $timeout = 10;
    // InfinityFree and some shared hosts disable fsockopen — guard against it
    if (!function_exists('fsockopen')) {
        error_log("SMTP: fsockopen is disabled on this server.");
        return false;
    }
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        error_log("SMTP Socket Connection failed: $errstr ($errno)");
        return false;
    }

    $getResponse = function($socket) {
        $response = "";
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (substr($line, 3, 1) == " ") {
                break;
            }
        }
        return $response;
    };

    $getResponse($socket);

    fwrite($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
    $getResponse($socket);

    if ($smtp['auth'] == '1') {
        fwrite($socket, "AUTH LOGIN\r\n");
        $getResponse($socket);

        fwrite($socket, base64_encode($user) . "\r\n");
        $getResponse($socket);

        fwrite($socket, base64_encode($pass) . "\r\n");
        $getResponse($socket);
    }

    fwrite($socket, "MAIL FROM:<" . $from . ">\r\n");
    $getResponse($socket);

    fwrite($socket, "RCPT TO:<" . $to . ">\r\n");
    $getResponse($socket);

    fwrite($socket, "DATA\r\n");
    $getResponse($socket);

    $boundary = "----=_Part_" . md5(uniqid(time()));

    $headers_str = "MIME-Version: 1.0\r\n";
    $headers_str .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <" . $from . ">\r\n";
    $headers_str .= "To: <" . $to . ">\r\n";
    $headers_str .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers_str .= "Date: " . date('r') . "\r\n";
    foreach ($headers as $k => $v) {
        $headers_str .= "$k: $v\r\n";
    }

    if (!empty($attachments)) {
        $headers_str .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        $headers_str .= "\r\n";

        // Body part
        $body_part = "--$boundary\r\n";
        $body_part .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body_part .= "Content-Transfer-Encoding: 7bit\r\n";
        $body_part .= "\r\n";
        $body_part .= $body . "\r\n";

        // Attachment parts
        foreach ($attachments as $att) {
            $filename = basename($att['filename']);
            $type = $att['type'] ?? 'application/octet-stream';
            $content_b64 = chunk_split(base64_encode($att['content']));

            $body_part .= "--$boundary\r\n";
            $body_part .= "Content-Type: $type; name=\"$filename\"\r\n";
            $body_part .= "Content-Transfer-Encoding: base64\r\n";
            $body_part .= "Content-Disposition: attachment; filename=\"$filename\"\r\n";
            $body_part .= "\r\n";
            $body_part .= $content_b64 . "\r\n";
        }
        $body_part .= "--$boundary--\r\n";

        $body_normalized = $body_part;
    } else {
        $headers_str .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers_str .= "\r\n";

        $body_normalized = $body;
    }

    $body_normalized = str_replace(["\r\n", "\r"], "\n", $body_normalized);
    $body_normalized = str_replace("\n", "\r\n", $body_normalized);
    $body_normalized = str_replace("\r\n.", "\r\n..", $body_normalized);

    fwrite($socket, $headers_str . $body_normalized . "\r\n.\r\n");
    $getResponse($socket);

    fwrite($socket, "QUIT\r\n");
    $getResponse($socket);

    fclose($socket);
    return true;
}

// Send real-time production mail via SMTP or PHP native fallback (No simulation/local caching)
function spool_mail($to, $subject, $body, $attachments = []) {
    global $pdo;
    
    // Load config values
    $from = 't2sodi@gmail.com';
    $contact_email = 'contact@timetoshine.co.in';
    $from_name = 'Time to Shine NGO';
    $tagline = 'A Journey from Darkness to Light';
    $phone = '7657059201';
    try {
        if (isset($pdo)) {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('email', 'site_name', 'tagline', 'phone')");
            while ($row = $stmt->fetch()) {
                if ($row['setting_key'] === 'email') { $contact_email = $row['setting_value']; }
                if ($row['setting_key'] === 'site_name') { $from_name = $row['setting_value']; }
                if ($row['setting_key'] === 'tagline') { $tagline = $row['setting_value']; }
                if ($row['setting_key'] === 'phone') { $phone = $row['setting_value']; }
            }
        }
    } catch (Exception $e) { }

    // Build the enhanced HTML email template
    $email_content = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>" . htmlspecialchars($subject) . "</title>
</head>
<body style='font-family: \"Outfit\", \"Inter\", \"Segoe UI\", Helvetica, Arial, sans-serif; line-height: 1.6; color: #1e293b; background-color: #fafaf9; margin: 0; padding: 20px 0;'>
    <div style='max-width: 580px; margin: 20px auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);'>
        <!-- Header Ribbon -->
        <div style='background: linear-gradient(135deg, #7c2d12 0%, #ea580c 50%, #f97316 100%); color: #ffffff; padding: 30px 20px; text-align: center;'>
            <h1 style='margin: 0; font-size: 24px; font-weight: 800; letter-spacing: 0.5px; text-shadow: 0 2px 4px rgba(0,0,0,0.15);'>" . htmlspecialchars($from_name) . "</h1>
            <p style='margin: 8px 0 0 0; font-size: 13px; color: rgba(255, 255, 255, 0.9); font-weight: 500; letter-spacing: 1px; text-transform: uppercase;'>" . htmlspecialchars($tagline) . "</p>
        </div>
        
        <!-- Email Body -->
        <div style='padding: 35px 30px; font-size: 15px; color: #334155; line-height: 1.75;'>
            " . $body . "
        </div>
        
        <!-- Footer -->
        <div style='background-color: #f8fafc; border-top: 1px solid #f1f5f9; padding: 25px 20px; font-size: 11px; color: #64748b; text-align: center; line-height: 1.6;'>
            <p style='margin: 0 0 8px 0; font-weight: 600; color: #475569;'>" . htmlspecialchars($from_name) . "</p>
            <p style='margin: 0 0 15px 0;'>Registered Charity Board | Odisha, India</p>
            <p style='margin: 0;'>&copy; " . date('Y') . " " . htmlspecialchars($from_name) . ". All rights reserved.</p>
            <p style='margin: 5px 0 0 0; font-size: 10px; color: #94a3b8;'>Phone: " . htmlspecialchars($phone) . " | Email: <a href='mailto:" . htmlspecialchars($contact_email) . "' style='color: #ea580c; text-decoration: none;'>" . htmlspecialchars($contact_email) . "</a></p>
        </div>
    </div>
</body>
</html>";
    
    // 1. Attempt SMTP real-time delivery
    if (send_smtp_socket_mail($to, $subject, $email_content, [], $attachments)) {
        return true;
    }
    
    // 2. Fall back to PHP native mail() function (real-time sending)
    if (!empty($attachments)) {
        $boundary = "----=_Part_" . md5(uniqid(time()));
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <" . $from . ">\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        
        $body_part = "--$boundary\r\n";
        $body_part .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body_part .= "Content-Transfer-Encoding: 7bit\r\n";
        $body_part .= "\r\n";
        $body_part .= $email_content . "\r\n";
        
        foreach ($attachments as $att) {
            $filename = basename($att['filename']);
            $type = $att['type'] ?? 'application/octet-stream';
            $content_b64 = chunk_split(base64_encode($att['content']));
            
            $body_part .= "--$boundary\r\n";
            $body_part .= "Content-Type: $type; name=\"$filename\"\r\n";
            $body_part .= "Content-Transfer-Encoding: base64\r\n";
            $body_part .= "Content-Disposition: attachment; filename=\"$filename\"\r\n";
            $body_part .= "\r\n";
            $body_part .= $content_b64 . "\r\n";
        }
        $body_part .= "--$boundary--\r\n";
        
        return @mail($to, $subject, $body_part, $headers);
    } else {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <" . $from . ">\r\n";
        
        return @mail($to, $subject, $email_content, $headers);
    }
}

// Helper to log upload errors for debugging on production
function upload_log($msg) {
    $log_file = __DIR__ . '/../upload_debug.log';
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// Helper to upload image — bulletproof for InfinityFree shared hosting
function upload_image_and_resize_webp($file_field_name, $target_dir, $width = 1600, $height = 900, $custom_name = null, $crop = true) {
    if (!isset($_FILES[$file_field_name])) {
        upload_log("ERROR: File field '$file_field_name' not in \$_FILES");
        return false;
    }

    $err_code = $_FILES[$file_field_name]['error'];
    if ($err_code !== UPLOAD_ERR_OK) {
        $php_upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in form',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder (server config issue)',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk (permissions issue)',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload',
        ];
        upload_log("UPLOAD_ERR: Code $err_code — " . ($php_upload_errors[$err_code] ?? 'Unknown error'));
        return false;
    }
    
    $tmp_name = $_FILES[$file_field_name]['tmp_name'];
    $original_name = $_FILES[$file_field_name]['name'];
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        upload_log("ERROR: Disallowed file extension: $ext");
        return false;
    }

    if (!is_uploaded_file($tmp_name)) {
        upload_log("ERROR: is_uploaded_file() failed for $tmp_name");
        return false;
    }
    
    // --- Resolve absolute target directory ---
    // Prefer DOCUMENT_ROOT-based path (more reliable on shared hosts like InfinityFree)
    // than __DIR__ which can be unreliable depending on symlink setups.
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if (!empty($doc_root) && is_dir($doc_root)) {
        $absolute_target_dir = $doc_root . '/' . ltrim($target_dir, '/');
    } else {
        // Fallback to __DIR__ relative path
        $absolute_target_dir = __DIR__ . '/../' . ltrim($target_dir, '/');
    }
    $absolute_target_dir = rtrim($absolute_target_dir, '/');
    
    upload_log("TARGET DIR: $absolute_target_dir");
    
    // Ensure directory exists and is writable
    if (!is_dir($absolute_target_dir)) {
        // Try creating it with 0755 — InfinityFree rejects 0777
        if (!@mkdir($absolute_target_dir, 0755, true)) {
            upload_log("ERROR: Could not create directory $absolute_target_dir");
            return false;
        }
        // Drop a blank index file to prevent directory browsing
        @file_put_contents($absolute_target_dir . '/index.html', '<!-- directory listing disabled -->');
    }
    
    if (!is_writable($absolute_target_dir)) {
        upload_log("ERROR: Directory not writable: $absolute_target_dir");
        return false;
    }
    
    $base_name = $custom_name ? $custom_name : uniqid('img_', true);
    $success   = false;
    $filename  = '';
    $target_file = '';
    
    // ── Strategy 1: GD with WebP output ─────────────────────────────────────
    if (!$success && extension_loaded('gd') && function_exists('imagewebp')) {
        $src = false;
        switch ($ext) {
            case 'jpg': case 'jpeg': $src = @imagecreatefromjpeg($tmp_name); break;
            case 'png':              $src = @imagecreatefrompng($tmp_name);  break;
            case 'gif':              $src = @imagecreatefromgif($tmp_name);  break;
            case 'webp':             $src = @imagecreatefromwebp($tmp_name); break;
        }
        if ($src) {
            // Auto crop logo borders (trim extra whitespace/transparency)
            if (function_exists('imagecropauto')) {
                $cropped = @imagecropauto($src, IMG_CROP_TRANSPARENT);
                if ($cropped !== false) {
                    imagedestroy($src);
                    $src = $cropped;
                }
                $cropped = @imagecropauto($src, IMG_CROP_DEFAULT);
                if ($cropped !== false) {
                    imagedestroy($src);
                    $src = $cropped;
                }
                $cropped = @imagecropauto($src, IMG_CROP_WHITE);
                if ($cropped !== false) {
                    imagedestroy($src);
                    $src = $cropped;
                }
            }

            $orig_w = imagesx($src);
            $orig_h = imagesy($src);
            
            $src_ratio = $orig_w / $orig_h;
            $dst_ratio = $width  / $height;
            
            if (!$crop) {
                if ($src_ratio >= $dst_ratio) {
                    $new_w = $width;
                    $new_h = $width / $src_ratio;
                } else {
                    $new_h = $height;
                    $new_w = $height * $src_ratio;
                }
                $width = $new_w;
                $height = $new_h;
                $src_x = 0; $src_y = 0;
                $src_w = $orig_w; $src_h = $orig_h;
            } else {
                if ($src_ratio >= $dst_ratio) {
                    $src_h = $orig_h; $src_w = $orig_h * $dst_ratio;
                    $src_x = ($orig_w - $src_w) / 2; $src_y = 0;
                } else {
                    $src_w = $orig_w; $src_h = $orig_w / $dst_ratio;
                    $src_x = 0; $src_y = ($orig_h - $src_h) / 2;
                }
            }

            $dst    = imagecreatetruecolor($width, $height);
            // Guard alpha functions — not compiled in all GD builds (e.g. InfinityFree)
            if (function_exists('imagealphachannel')) {
                imagealphachannel($dst, true);
            }
            if (function_exists('imagecolorallocatealpha')) {
                $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
                imagefill($dst, 0, 0, $transparent);
            } else {
                // Fallback: fill with white background
                $bg = imagecolorallocate($dst, 255, 255, 255);
                imagefill($dst, 0, 0, $bg);
            }
            
            imagecopyresampled($dst, $src, 0, 0, $src_x, $src_y, $width, $height, $src_w, $src_h);
            
            $filename    = $base_name . '.webp';
            $target_file = $absolute_target_dir . '/' . $filename;
            $success     = @imagewebp($dst, $target_file, 85);
            if (!$success) { upload_log("GD WebP write failed: $target_file"); }
            imagedestroy($src); imagedestroy($dst);
        } else {
            upload_log("GD could not read source image from: $tmp_name (ext=$ext)");
        }
    }

    // ── Strategy 2: GD with JPEG output (fallback when imagewebp() missing) ──
    if (!$success && extension_loaded('gd')) {
        $src = false;
        switch ($ext) {
            case 'jpg': case 'jpeg': $src = @imagecreatefromjpeg($tmp_name); break;
            case 'png':              $src = @imagecreatefrompng($tmp_name);  break;
            case 'gif':              $src = @imagecreatefromgif($tmp_name);  break;
            case 'webp':             $src = @imagecreatefromwebp($tmp_name); break;
        }
        if ($src) {
            // Auto crop logo borders (trim extra whitespace/transparency)
            if (function_exists('imagecropauto')) {
                $cropped = @imagecropauto($src, IMG_CROP_TRANSPARENT);
                if ($cropped !== false) {
                    imagedestroy($src);
                    $src = $cropped;
                }
                $cropped = @imagecropauto($src, IMG_CROP_DEFAULT);
                if ($cropped !== false) {
                    imagedestroy($src);
                    $src = $cropped;
                }
                $cropped = @imagecropauto($src, IMG_CROP_WHITE);
                if ($cropped !== false) {
                    imagedestroy($src);
                    $src = $cropped;
                }
            }

            $orig_w = imagesx($src);
            $orig_h = imagesy($src);
            
            $src_ratio = $orig_w / $orig_h;
            $dst_ratio = $width  / $height;
            
            if (!$crop) {
                if ($src_ratio >= $dst_ratio) {
                    $new_w = $width;
                    $new_h = $width / $src_ratio;
                } else {
                    $new_h = $height;
                    $new_w = $height * $src_ratio;
                }
                $width = $new_w;
                $height = $new_h;
                $src_x = 0; $src_y = 0;
                $src_w = $orig_w; $src_h = $orig_h;
            } else {
                if ($src_ratio >= $dst_ratio) {
                    $src_h = $orig_h; $src_w = $orig_h * $dst_ratio;
                    $src_x = ($orig_w - $src_w) / 2; $src_y = 0;
                } else {
                    $src_w = $orig_w; $src_h = $orig_w / $dst_ratio;
                    $src_x = 0; $src_y = ($orig_h - $src_h) / 2;
                }
            }
            
            $dst    = imagecreatetruecolor($width, $height);
            // Use plain white background — safe on all GD builds
            if (function_exists('imagecolorallocate')) {
                $bg_white = imagecolorallocate($dst, 255, 255, 255);
                imagefill($dst, 0, 0, $bg_white);
            }
            
            imagecopyresampled($dst, $src, 0, 0, $src_x, $src_y, $width, $height, $src_w, $src_h);
            
            $filename    = $base_name . '.jpg';
            $target_file = $absolute_target_dir . '/' . $filename;
            $success     = @imagejpeg($dst, $target_file, 85);
            if (!$success) { upload_log("GD JPEG write failed: $target_file"); }
            imagedestroy($src); imagedestroy($dst);
        }
    }
    
    // ── Strategy 3: Imagick (if available) ───────────────────────────────────
    if (!$success && class_exists('Imagick')) {
        try {
            $im = new Imagick($tmp_name);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(85);
            $im->cropThumbnailImage($width, $height);
            $filename    = $base_name . '.jpg';
            $target_file = $absolute_target_dir . '/' . $filename;
            $success     = $im->writeImage($target_file);
            $im->clear(); $im->destroy();
            if (!$success) { upload_log("Imagick write failed: $target_file"); }
        } catch (Exception $e) {
            upload_log("Imagick exception: " . $e->getMessage());
            $success = false;
        }
    }

    // ── Strategy 4: Raw move (last resort — no resizing) ─────────────────────
    if (!$success) {
        $filename    = $base_name . '.' . $ext;
        $target_file = $absolute_target_dir . '/' . $filename;
        $success     = @move_uploaded_file($tmp_name, $target_file);
        if ($success) {
            upload_log("FALLBACK raw move_uploaded_file succeeded: $target_file");
        } else {
            upload_log("CRITICAL: All strategies failed. move_uploaded_file also failed: $target_file");
        }
    }
    
    upload_log($success ? "SUCCESS: Saved as $filename" : "FAILURE: All upload strategies exhausted.");
    return $success ? $filename : false;
}

// Helper to convert standard YouTube links to embed format
function get_youtube_embed_url($url) {
    $video_id = '';
    if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $url, $match)) {
        $video_id = $match[1];
    }
    return !empty($video_id) ? "https://www.youtube.com/embed/" . $video_id : $url;
}
