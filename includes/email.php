<?php
/**
 * Email notification helpers
 */

/**
 * Send an email notification when new feedback is submitted.
 *
 * @param  PDO   $pdo
 * @param  array $feedback_data  Associative array of the submitted feedback row
 * @return bool
 */
function send_feedback_notification(PDO $pdo, array $feedback_data): bool
{
    try {
        $settings = get_site_settings($pdo);
        $emails   = trim($settings['notification_emails'] ?? '');
        if ($emails === '') {
            return false;
        }

        // Support comma-separated list of recipients
        $recipients = array_filter(array_map('trim', explode(',', $emails)));
        if (empty($recipients)) {
            return false;
        }

        $company  = h($settings['company_name'] ?? APP_NAME);
        $category = ucfirst(h($feedback_data['category'] ?? 'feedback'));
        $dept     = h($feedback_data['dept_name']    ?? $feedback_data['other_department'] ?? 'General');
        $rating   = (int)($feedback_data['rating'] ?? 0);
        $stars    = $rating > 0 ? star_rating($rating) : '—';
        $message  = nl2br(h($feedback_data['message'] ?? ''));
        $date     = format_date($feedback_data['created_at'] ?? date('Y-m-d H:i:s'));
        $submitter = $feedback_data['is_anonymous']
            ? 'Anonymous'
            : h($feedback_data['submitter_name'] ?? 'Unknown');
        $id       = (int)($feedback_data['id'] ?? 0);
        $detailUrl = BASE_URL . '/dashboard/feedback-detail.php?id=' . $id;

        // Category colour
        $catColour = match($feedback_data['category'] ?? '') {
            'compliment' => '#16a34a',
            'complaint'  => '#dc2626',
            default      => '#2563eb',
        };

        $subject = "[{$company}] New {$category} — {$dept}";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f0;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f0;padding:32px 16px;">
<tr><td align="center">
  <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
    <!-- Header -->
    <tr>
      <td style="background:linear-gradient(135deg,#1B3A1B 0%,#245924 100%);padding:28px 32px;">
        <h1 style="margin:0;color:#44B944;font-size:22px;font-weight:700;letter-spacing:-0.3px;">{$company}</h1>
        <p style="margin:4px 0 0;color:rgba(255,255,255,0.7);font-size:13px;">New Feedback Notification</p>
      </td>
    </tr>
    <!-- Badge row -->
    <tr>
      <td style="padding:24px 32px 0;">
        <span style="display:inline-block;background:{$catColour};color:#fff;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;padding:4px 12px;border-radius:20px;">{$category}</span>
        &nbsp;
        <span style="display:inline-block;background:#f1f5f9;color:#475569;font-size:12px;font-weight:500;padding:4px 12px;border-radius:20px;">{$dept}</span>
      </td>
    </tr>
    <!-- Meta -->
    <tr>
      <td style="padding:16px 32px 0;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="width:50%;padding-bottom:12px;">
              <p style="margin:0;font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">Rating</p>
              <p style="margin:4px 0 0;font-size:20px;color:#f59e0b;">{$stars}</p>
            </td>
            <td style="width:50%;padding-bottom:12px;">
              <p style="margin:0;font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">Submitted by</p>
              <p style="margin:4px 0 0;font-size:14px;color:#1e293b;font-weight:500;">{$submitter}</p>
            </td>
          </tr>
          <tr>
            <td colspan="2" style="padding-bottom:12px;">
              <p style="margin:0;font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">Date</p>
              <p style="margin:4px 0 0;font-size:14px;color:#1e293b;">{$date}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <!-- Message -->
    <tr>
      <td style="padding:16px 32px 24px;">
        <p style="margin:0 0 8px;font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">Message</p>
        <div style="background:#f8faf8;border-left:4px solid #44B944;border-radius:0 8px 8px 0;padding:16px;font-size:14px;color:#334155;line-height:1.6;">
          {$message}
        </div>
      </td>
    </tr>
    <!-- CTA -->
    <tr>
      <td style="padding:0 32px 32px;text-align:center;">
        <a href="{$detailUrl}" style="display:inline-block;background:linear-gradient(135deg,#1B3A1B,#245924);color:#44B944;text-decoration:none;font-weight:600;font-size:14px;padding:12px 28px;border-radius:8px;letter-spacing:0.3px;">
          View in Dashboard →
        </a>
      </td>
    </tr>
    <!-- Footer -->
    <tr>
      <td style="background:#f8faf8;padding:16px 32px;border-top:1px solid #e2e8e2;text-align:center;">
        <p style="margin:0;font-size:12px;color:#94a3b8;">This is an automated notification from {$company} Feedback System.</p>
      </td>
    </tr>
  </table>
</td></tr>
</table>
</body>
</html>
HTML;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$company} Feedback <noreply@sgir.com>\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

        $allSent = true;
        foreach ($recipients as $to) {
            $sent = mail($to, $subject, $html, $headers);
            if (!$sent) {
                $allSent = false;
                error_log("Mail failed to: {$to}");
            }
        }
        return $allSent;
    } catch (Throwable $e) {
        error_log('send_feedback_notification error: ' . $e->getMessage());
        return false;
    }
}
