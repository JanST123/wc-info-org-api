<?php

namespace App\Services;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

class MailService
{
    public function send(string $to, string $subject, string $body, $isHtml = false): void
    {
        $config = config('wcinfo.smtp');
        $from = config('wcinfo.sender_mail');

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['user'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $this->mapEncryption($config['encryption']);
            $mail->Port = $config['port'];
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom($from);
            $mail->addAddress($to);
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new RuntimeException('Failed to send mail: '.$e->getMessage(), 0, $e);
        }
    }

    private function mapEncryption(string $encryption): string
    {
        return match (strtolower($encryption)) {
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => PHPMailer::ENCRYPTION_SMTPS,
        };
    }
}
