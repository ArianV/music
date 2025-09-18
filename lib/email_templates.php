<?php
// lib/email_templates.php

if (!function_exists('render_verify_email')) {
  /**
   * Render PlugBio verification email (HTML + text).
   * Returns array: [subject, html, text]
   */
  function render_verify_email(string $name, string $verifyUrl): array {
    $brand     = 'PlugBio';
    $accent    = '#22c55e';   // your mint/green CTA
    $bg        = '#0b0b0c';
    $card      = '#111318';
    $border    = '#1f2430';
    $muted     = '#9ca3af';
    $textCol   = '#e5e7eb';
    $preheader = "Confirm your email to finish setting up your $brand account.";

    // plaintext fallback (for clients that don't render HTML)
    $text = "Hey {$name},\n\n"
          . "Tap the link below to verify your email for {$brand}:\n"
          . "{$verifyUrl}\n\n"
          . "If you didn’t create an account, you can ignore this message.";

    // Use a bulletproof table layout + inline styles for broad email client support
    $subject = "Verify your email • {$brand}";

    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$subject}</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="dark light">
  <meta name="supported-color-schemes" content="dark light">
  <style>
    /* Clients with style support */
    @media (max-width: 600px) {
      .container { width: 100% !important; }
      .card { padding: 20px !important; }
      .btn a { display:block !important; }
    }
    /* Outlook dark mode tweaks */
    :root { color-scheme: dark; supported-color-schemes: dark light; }
  </style>
</head>
<body style="margin:0;padding:0;background:{$bg};color:{$textCol};">
  <!-- Preheader (hidden preview text) -->
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
    {$preheader}
    \u200B\u200B\u200B\u200B\u200B\u200B\u200B\u200B\u200B\u200B
  </div>

  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
         style="background: radial-gradient(800px 400px at 75% 85%, rgba(34,197,94,0.12), transparent 55%),
                 radial-gradient(700px 360px at 20% 20%, rgba(59,130,246,0.08), transparent 60%),
                 {$bg};">
    <tr>
      <td align="center" style="padding:32px 16px;">
        <table role="presentation" width="600" class="container" cellspacing="0" cellpadding="0" border="0" style="width:600px;max-width:600px;">
          <!-- Brand row -->
          <tr>
            <td align="left" style="padding:0 8px 18px 8px; font-family: ui-sans-serif, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;">
              <span style="display:inline-flex;align-items:center;gap:8px;">
                <!-- Simple inline “plug” icon -->
                <span style="display:inline-block;width:22px;height:22px;border-radius:6px;background:{$accent};
                             box-shadow:0 0 0 3px rgba(34,197,94,0.18) inset;"></span>
                <span style="font-weight:700;letter-spacing:.2px;color:{$textCol};font-size:14px;">PlugBio</span>
              </span>
            </td>
          </tr>

          <!-- Card -->
          <tr>
            <td style="border:1px solid {$border};border-radius:14px;background:{$card};padding:26px;"
                class="card" align="left">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td style="font-family: ui-sans-serif, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;">
                    <h1 style="margin:0 0 8px 0;font-size:22px;line-height:1.3;color:{$textCol};">
                      Verify your email
                    </h1>
                    <p style="margin:0 0 18px 0;font-size:14px;color:{$muted};">
                      Hey {$name}, click the button below to confirm your email for {$brand}.
                    </p>

                    <!-- Button (bulletproof) -->
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" class="btn" style="margin:18px 0 8px 0;">
                      <tr>
                        <td align="left">
                          <!--[if mso]>
                          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                                       href="{$verifyUrl}" style="height:40px;v-text-anchor:middle;width:180px;"
                                       arcsize="12%" stroke="f" fillcolor="{$accent}">
                            <w:anchorlock/>
                            <center style="color:#0b0b0c;font-family:Segoe UI, Arial,sans-serif;font-size:14px;font-weight:bold;">
                              Verify email
                            </center>
                          </v:roundrect>
                          <![endif]-->
                          <!--[if !mso]><!-- -->
                          <a href="{$verifyUrl}"
                             style="background:{$accent};color:#0b0b0c;text-decoration:none;font-weight:700;
                                    font-size:14px;display:inline-block;padding:11px 18px;border-radius:10px;">
                            Verify email
                          </a>
                          <!--<![endif]-->
                        </td>
                      </tr>
                    </table>

                    <!-- Fallback URL -->
                    <p style="margin:16px 0 0 0;font-size:12px;color:{$muted};line-height:1.5;">
                      If the button doesn’t work, paste this link into your browser:<br>
                      <a href="{$verifyUrl}" style="color:{$accent};text-decoration:underline;">{$verifyUrl}</a>
                    </p>

                    <hr style="border:none;border-top:1px solid {$border};margin:22px 0 12px 0;">

                    <p style="margin:0;font-size:12px;color:{$muted};">
                      If you didn’t create an account, you can safely ignore this email.
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td align="left" style="padding:14px 8px 0 8px;color:{$muted};
                                    font-family: ui-sans-serif, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
                                    font-size:12px;line-height:1.5;">
              Sent by PlugBio · Please don’t reply to this address.
            </td>
          </tr>
          <tr>
            <td align="left" style="padding:4px 8px 0 8px;color:{$muted};
                                    font-family: ui-sans-serif, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
                                    font-size:12px;">
              <a href="https://plugbio.app" style="color:{$accent};text-decoration:none;">plugbio.app</a>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

    return [$subject, $html, $text];
  }
}

function send_verification_email(string $toEmail, string $toName, string $verifyUrl): bool {
  [$subject, $html, $text] = render_verify_email($toName ?: 'there', $verifyUrl);

  // If you have a generic send function already:
  // return send_mail_smtp($toEmail, $subject, $html, $text);

  // Or, using PHPMailer (example):
  $mail = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host = getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com';
    $mail->SMTPAuth = true;
    $mail->Username = getenv('SMTP_USER'); // e.g., 974aee001@smtp-brevo.com
    $mail->Password = getenv('SMTP_PASS'); // your API key / master password
    $mail->Port = 587;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

    $mail->CharSet = 'UTF-8';
    $mail->setFrom(getenv('MAIL_FROM') ?: 'do-no-reply@plugb.io', 'PlugBio');
    $mail->addAddress($toEmail, $toName ?: '');
    $mail->Subject = $subject;

    // Better inboxing: both HTML and plain text
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = $text;

    // Optional: List-Unsubscribe header (good for deliverability)
    $mail->addCustomHeader('List-Unsubscribe', '<mailto:unsubscribe@plugb.io>, <https://plugbio.app/u/unsub>');

    return $mail->send();
  } catch (Throwable $e) {
    error_log('Email send failed: '.$e->getMessage());
    return false;
  }
}

if (!function_exists('render_change_email')) {
  /**
   * Render PlugBio "Confirm new email" template (HTML + text).
   * Returns array: [subject, html, text]
   */
  function render_change_email(string $name, string $verifyUrl): array {
    // Reuse verify template parts but with different copy
    [$subject, $html, $text] = render_verify_email($name, $verifyUrl);

    $subject = 'Confirm your new email - PlugBio';
    $html = str_replace('Verify your email', 'Confirm your new email', $html);
    $html = str_replace('Verify email', 'Confirm email', $html);
    $text = str_replace('Verify your email', 'Confirm your new email', $text);
    $text = str_replace('Verify email', 'Confirm email', $text);

    return [$subject, $html, $text];
  }
}
