<?php
/**
 * _mailer.php — Shared PHPMailer transport factory.
 *
 * USAGE
 *   require_once __DIR__ . '/_mailer.php';        // from project root
 *   require_once __DIR__ . '/../_mailer.php';     // from admin/ or user/
 *   $mail = make_mailer();
 *   $mail->addAddress($to, $name);
 *   $mail->isHTML(true);
 *   $mail->Subject = '…';
 *   $mail->Body    = '…';
 *   $mail->AltBody = '…';
 *   $mail->send();
 *
 * NEVER configure isSMTP / Host / Username / Password / Port / SMTPSecure
 * in the individual senders — configure only in this file.
 *
 * GMAIL REQUIREMENT
 *   MAIL_PORT=587 uses STARTTLS (recommended).
 *   MAIL_PORT=465 uses SMTPS (implicit TLS).
 *   MAIL_PASSWORD must be a 16-character Gmail App Password — NOT your normal
 *   Gmail password.  Generate one at:
 *   https://myaccount.google.com/apppasswords  (requires 2FA to be enabled).
 */

// Load PHPMailer via Composer autoload if not already loaded.
if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    $__al = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($__al)) {
        $__al = (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '')
              . '/spa_ecommerce_system/vendor/autoload.php';
    }
    if (!file_exists($__al)) {
        throw new \RuntimeException('PHPMailer autoload not found. Run: composer install');
    }
    require_once $__al;
    unset($__al);
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Build and return a fully-configured PHPMailer ready to send.
 *
 * Transport settings come exclusively from MAIL_* constants (loaded from .env
 * by config.php).  Encryption is derived from the port:
 *   587  → STARTTLS   (Gmail default — use this)
 *   465  → SMTPS (implicit TLS)
 *   other → STARTTLS
 *
 * @throws \RuntimeException if MAIL_* constants are missing or credentials empty
 * @throws \PHPMailer\PHPMailer\Exception if PHPMailer rejects the config
 */
function make_mailer(): PHPMailer {
    if (!defined('MAIL_HOST')) {
        throw new \RuntimeException(
            'MAIL_* constants are not defined. Ensure config.php is required before _mailer.php.'
        );
    }
    if (MAIL_USERNAME === '' || MAIL_PASSWORD === '') {
        throw new \RuntimeException(
            'MAIL_USERNAME / MAIL_PASSWORD are empty. Set them in .env. '
            . 'Gmail requires a 16-char App Password, not your normal password.'
        );
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host     = MAIL_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = MAIL_USERNAME;
    $mail->Password = MAIL_PASSWORD;
    $mail->Port     = (int) MAIL_PORT;

    // Encryption must match the port — mismatches cause "SMTP Error: Could not authenticate".
    if ((int) MAIL_PORT === 465) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;      // implicit TLS
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;   // STARTTLS (587 / Gmail default)
    }

    $mail->setFrom(MAIL_FROM, MAIL_NAME);
    $mail->CharSet   = 'UTF-8';
    $mail->Timeout   = 15;     // seconds; prevents indefinite hangs on unreachable SMTP
    $mail->SMTPDebug = SMTP::DEBUG_OFF;  // 0 in production; set SMTP::DEBUG_SERVER for traces

    return $mail;
}
