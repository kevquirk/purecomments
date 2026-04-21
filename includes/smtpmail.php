<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

function smtp_send_email(array $config, string $to, string $subject, string $textBody, string $htmlBody = '', ?string &$debugLog = null): bool
{
    $smtp = $config['smtp'];
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->Port       = (int)$smtp['port'];
        $mail->SMTPAuth   = $smtp['user'] !== '';
        $mail->Username   = $smtp['user'];
        $mail->Password   = $smtp['pwd'];
        $mail->SMTPSecure = $smtp['enc'];
        $mail->CharSet    = PHPMailer::CHARSET_UTF8;
        $mail->Encoding   = 'quoted-printable';

        if (!empty($smtp['debug'])) {
            $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function (string $str, int $level) use (&$debugLog): void {
                $debugLog .= $str;
            };
        }

        $mail->setFrom($config['author']['email'], $config['author']['name']);
        $mail->addAddress($to);

        $mail->Subject = $subject;

        if ($htmlBody !== '') {
            $mail->isHTML(true);
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;
        } else {
            $mail->Body = $textBody;
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('SMTP send failed: ' . $mail->ErrorInfo);
        return false;
    }
}
