<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

function sendMail(string $to, string $toName, string $subject, string $htmlBody): bool {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('BH-Tracker mailer: vendor/autoload.php não encontrado. Rode composer install.');
        return false;
    }
    require_once $autoload;

    $host     = getenv('MAIL_HOST')      ?: '';
    $port     = (int)(getenv('MAIL_PORT') ?: 587);
    $user     = getenv('MAIL_USER')      ?: '';
    $pass     = getenv('MAIL_PASS')      ?: '';
    $from     = getenv('MAIL_FROM')      ?: 'noreply@hostweb.cloud';
    $fromName = getenv('MAIL_FROM_NAME') ?: 'BH Tecnologia';

    if (!$host || !$user) {
        error_log('BH-Tracker mailer: MAIL_HOST ou MAIL_USER não configurados.');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = $port === 465
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('BH-Tracker mailer error: ' . $e->getMessage());
        return false;
    }
}

function mailRejectRecord(string $toEmail, string $toName, string $ticket, string $startedAt, string $endedAt, string $reason): bool {
    $subject = 'Registro recusado — ' . $ticket;
    $html = '
    <div style="font-family:system-ui,sans-serif;max-width:520px;margin:0 auto;color:#1f2937">
      <div style="background:linear-gradient(135deg,#e8001c 0%,#6b0fa8 100%);padding:24px 32px;border-radius:12px 12px 0 0">
        <h1 style="color:#fff;margin:0;font-size:20px">BH Tecnologia · Hostweb</h1>
      </div>
      <div style="background:#fff;border:1px solid #e5e7eb;border-top:none;padding:32px;border-radius:0 0 12px 12px">
        <p style="margin:0 0 8px">Olá, <strong>' . htmlspecialchars($toName) . '</strong></p>
        <p style="margin:0 0 24px;color:#6b7280">Seu registro de acionamento foi <strong style="color:#e8001c">recusado</strong> pelo coordenador.</p>
        <table style="width:100%;border-collapse:collapse;margin-bottom:24px">
          <tr style="background:#f9fafb">
            <td style="padding:10px 14px;font-size:13px;color:#6b7280;width:40%">Chamado</td>
            <td style="padding:10px 14px;font-size:13px;font-weight:600">' . htmlspecialchars($ticket) . '</td>
          </tr>
          <tr>
            <td style="padding:10px 14px;font-size:13px;color:#6b7280">Período</td>
            <td style="padding:10px 14px;font-size:13px">' . htmlspecialchars($startedAt) . ' → ' . htmlspecialchars($endedAt) . '</td>
          </tr>
          <tr style="background:#f9fafb">
            <td style="padding:10px 14px;font-size:13px;color:#6b7280">Motivo</td>
            <td style="padding:10px 14px;font-size:13px;color:#dc2626">' . nl2br(htmlspecialchars($reason)) . '</td>
          </tr>
        </table>
        <p style="margin:0;font-size:13px;color:#6b7280">Em caso de dúvidas, entre em contato com seu coordenador.</p>
      </div>
    </div>';
    return sendMail($toEmail, $toName, $subject, $html);
}

function mailPasswordReset(string $toEmail, string $toName, string $resetLink): bool {
    $subject = 'Redefinição de senha — BH Tecnologia';
    $html = '
    <div style="font-family:system-ui,sans-serif;max-width:520px;margin:0 auto;color:#1f2937">
      <div style="background:linear-gradient(135deg,#e8001c 0%,#6b0fa8 100%);padding:24px 32px;border-radius:12px 12px 0 0">
        <h1 style="color:#fff;margin:0;font-size:20px">BH Tecnologia · Hostweb</h1>
      </div>
      <div style="background:#fff;border:1px solid #e5e7eb;border-top:none;padding:32px;border-radius:0 0 12px 12px">
        <p style="margin:0 0 8px">Olá, <strong>' . htmlspecialchars($toName) . '</strong></p>
        <p style="margin:0 0 24px;color:#6b7280">Recebemos uma solicitação para redefinir a senha da sua conta. Clique no botão abaixo para criar uma nova senha. O link expira em <strong>1 hora</strong>.</p>
        <div style="text-align:center;margin:32px 0">
          <a href="' . htmlspecialchars($resetLink) . '"
             style="background:linear-gradient(135deg,#e8001c 0%,#6b0fa8 100%);color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:600;font-size:15px;display:inline-block">
            Redefinir senha
          </a>
        </div>
        <p style="margin:0 0 8px;font-size:13px;color:#6b7280">Se você não solicitou isso, ignore este e-mail — sua senha permanece a mesma.</p>
        <p style="margin:0;font-size:12px;color:#9ca3af;word-break:break-all">Link: ' . htmlspecialchars($resetLink) . '</p>
      </div>
    </div>';
    return sendMail($toEmail, $toName, $subject, $html);
}
