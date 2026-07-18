<?php
/**
 * Winngoo Group — Contact Form Mail Handler
 * Raw SMTP over SSL port 465 (no Composer / PHPMailer needed)
 */

// ── SMTP Configuration ─────────────────────────────────────────
define('SMTP_HOST',      'mail.wimbgo.com');
define('SMTP_PORT',      465);
define('SMTP_USERNAME',  'info@wimbgo.com');
define('SMTP_PASSWORD',  'M2bTKtZEVGsY33D#');
define('MAIL_FROM',      'support@wimbgo.com');
define('MAIL_FROM_NAME', 'Winngoo Group Enquiry');
define('RECIPIENT_EMAIL','thilagar@vishakarex.in');
define('RECIPIENT_NAME', 'Thilagar');
// ───────────────────────────────────────────────────────────────

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Sanitize helper ────────────────────────────────────────────
function clean(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

// ── Collect & sanitize ─────────────────────────────────────────
$name    = clean($_POST['name']    ?? '');
$email   = clean($_POST['email']   ?? '');
$phone   = clean($_POST['phone']   ?? '');
$subject = clean($_POST['subject'] ?? '');
$message = clean($_POST['message'] ?? '');

// ── Validation ─────────────────────────────────────────────────
$errors = [];

if ($name === '' || !preg_match('/^[a-zA-Z\s]{2,}$/', $name))
    $errors[] = 'Valid full name is required (letters only).';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
    $errors[] = 'A valid email address is required.';

if ($phone === '' || !preg_match('/^\+?[\d\s\-().]{7,15}$/', $phone))
    $errors[] = 'A valid phone number is required.';

if ($subject === '')
    $errors[] = 'Subject is required.';

if ($message === '' || strlen($message) < 10)
    $errors[] = 'Message must be at least 10 characters.';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── Build HTML email body ──────────────────────────────────────
$year         = date('Y');
$templatePath = __DIR__ . '/mail/mail.html';

if (!file_exists($templatePath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Email template not found'
    ]);
    exit;
}

$htmlBody = file_get_contents($templatePath);

// ── Replace placeholders ───────────────────────────────────────
$htmlBody = str_replace('{NAME}',    $name,           $htmlBody);
$htmlBody = str_replace('{EMAIL}',   $email,          $htmlBody);
$htmlBody = str_replace('{PHONE}',   $phone,          $htmlBody);
$htmlBody = str_replace('{SUBJECT}', $subject,        $htmlBody);
$htmlBody = str_replace('{MESSAGE}', nl2br($message), $htmlBody);
$htmlBody = str_replace('{YEAR}',    $year,           $htmlBody);

// ── Plain text fallback ────────────────────────────────────────
$plainText = implode("\n", [
    "New Enquiry — Winngoo Group",
    str_repeat("-", 40),
    "Name    : $name",
    "Email   : $email",
    "Phone   : $phone",
    "Subject : $subject",
    "",
    "Message:",
    $message,
    "",
    str_repeat("-", 40),
    "Sent via winngoo-group contact form",
]);

$mailSubject = '[Winngoo Enquiry] ' . $subject . ' — ' . $name;

// ── Read ALL lines of a multi-line SMTP response ───────────────
function smtpRead($socket): string {
    $last = '';
    while (true) {
        $line = fgets($socket, 1024);
        if ($line === false) break;
        $last = $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return $last;
}

// ── Send a command and return the response ─────────────────────
function smtpCommand($socket, string $cmd): string {
    fwrite($socket, $cmd . "\r\n");
    return smtpRead($socket);
}

// ── Core SMTP send over SSL port 465 ──────────────────────────
function smtpSend(
    string $host, int $port,
    string $user, string $pass,
    string $from, string $fromName,
    string $to,   string $toName,
    string $subjectLine,
    string $htmlBody,
    string $plainText
): bool|string {

    $boundary = md5(uniqid('', true));

    // Build headers
    $headers  = "Date: "      . date('r')                                         . "\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>"     . "\r\n";
    $headers .= "Reply-To: <$from>"                                               . "\r\n";
    $headers .= "To: =?UTF-8?B?"   . base64_encode($toName)   . "?= <$to>"       . "\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subjectLine) . "?="       . "\r\n";
    $headers .= "MIME-Version: 1.0"                                               . "\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\""    . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion()                                   . "\r\n";

    // Build body
    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plainText)) . "\r\n";

    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

    $body .= "--$boundary--\r\n";

    $fullMessage = $headers . "\r\n" . $body;

    // Open SSL socket on port 465
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ]);

    $socket = stream_socket_client(
        "ssl://$host:$port",
        $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT, $ctx
    );

    if (!$socket) {
        return "Connection failed: $errstr ($errno)";
    }

    stream_set_timeout($socket, 15);

    // 1. Greeting
    $greeting = smtpRead($socket);
    if (substr($greeting, 0, 3) !== '220') {
        fclose($socket);
        return "Bad greeting: " . trim($greeting);
    }

    // 2. EHLO
    fwrite($socket, "EHLO " . (gethostname() ?: 'localhost') . "\r\n");
    $ehlo = smtpRead($socket);
    if (substr($ehlo, 0, 3) !== '250') {
        fclose($socket);
        return "EHLO failed: " . trim($ehlo);
    }

    // 3. AUTH LOGIN
    $authResp = smtpCommand($socket, "AUTH LOGIN");
    if (substr($authResp, 0, 3) !== '334') {
        fclose($socket);
        return "AUTH LOGIN failed: " . trim($authResp);
    }

    // 4. Username
    $userResp = smtpCommand($socket, base64_encode($user));
    if (substr($userResp, 0, 3) !== '334') {
        fclose($socket);
        return "Username rejected: " . trim($userResp);
    }

    // 5. Password
    $passResp = smtpCommand($socket, base64_encode($pass));
    if (substr($passResp, 0, 3) !== '235') {
        fclose($socket);
        return "Password rejected: " . trim($passResp);
    }

    // 6. MAIL FROM
    $mfResp = smtpCommand($socket, "MAIL FROM:<$from>");
    if (substr($mfResp, 0, 3) !== '250') {
        fclose($socket);
        return "MAIL FROM failed: " . trim($mfResp);
    }

    // 7. RCPT TO
    $rtResp = smtpCommand($socket, "RCPT TO:<$to>");
    if (substr($rtResp, 0, 3) !== '250') {
        fclose($socket);
        return "RCPT TO failed: " . trim($rtResp);
    }

    // 8. DATA
    $dataResp = smtpCommand($socket, "DATA");
    if (substr($dataResp, 0, 3) !== '354') {
        fclose($socket);
        return "DATA command failed: " . trim($dataResp);
    }

    // 9. Send message
    fwrite($socket, $fullMessage . "\r\n.\r\n");
    $msgResp = smtpRead($socket);
    if (substr($msgResp, 0, 3) !== '250') {
        fclose($socket);
        return "Message rejected: " . trim($msgResp);
    }

    // 10. QUIT
    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}

// ── Execute ────────────────────────────────────────────────────
$result = smtpSend(
    SMTP_HOST, SMTP_PORT,
    SMTP_USERNAME, SMTP_PASSWORD,
    MAIL_FROM, MAIL_FROM_NAME,
    RECIPIENT_EMAIL, RECIPIENT_NAME,
    $mailSubject,
    $htmlBody,
    $plainText
);

// ── Respond ────────────────────────────────────────────────────
if ($result === true) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Your enquiry has been sent successfully!'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Mail error: ' . $result
    ]);
}