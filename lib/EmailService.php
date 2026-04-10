<?php

declare(strict_types=1);

final class EmailService
{
    private static function escape(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param list<string> $to */
    public static function send(string|array $to, string $subject, string $html, ?string $replyTo = null): bool
    {
        $recipients = is_array($to) ? $to : [$to];
        $key = config('RESEND_API_KEY', '');
        if ($key !== '') {
            $host = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
            $payload = [
                'from' => 'Home Value <noreply@' . $host . '>',
                'to' => $recipients,
                'subject' => $subject,
                'html' => $html,
            ];
            if ($replyTo) {
                $payload['reply_to'] = $replyTo;
            }
            $ch = curl_init('https://api.resend.com/emails');
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $key,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                ]);
                $raw = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($raw !== false && $code >= 200 && $code < 300) {
                    return true;
                }
            }
        }

        return Birdview::sendEmail($recipients, $subject, $html, true);
    }

    public static function sendRegistrationNotification(array $data): bool
    {
        $id = (int) $data['registrationId'];
        $site = config('SITE_URL', base_url());
        $secret = require_env('JWT_SECRET');
        $token = ApprovalToken::generate($id, $secret);
        $base = rtrim($site, '/');
        $approveUrl = $base . '/api/admin/registrations/approve.php?token=' . rawurlencode($token) . '&action=approve';
        $rejectUrl = $base . '/api/admin/registrations/approve.php?token=' . rawurlencode($token) . '&action=reject';

        $subject = 'New Registration: ' . $data['companyName'] . ' — ' . $data['contactName'];
        $html = '<h2>New Customer Registration</h2><table style="border-collapse:collapse;width:100%;max-width:500px;">'
            . '<tr><td style="padding:8px;font-weight:bold;">Contact Name</td><td style="padding:8px;">' . self::escape($data['contactName']) . '</td></tr>'
            . '<tr><td style="padding:8px;font-weight:bold;">Company</td><td style="padding:8px;">' . self::escape($data['companyName']) . '</td></tr>'
            . '<tr><td style="padding:8px;font-weight:bold;">Email</td><td style="padding:8px;">' . self::escape($data['email']) . '</td></tr>'
            . '</table><br/>'
            . '<a href="' . self::escape($approveUrl) . '" style="display:inline-block;padding:12px 24px;background:#22c55e;color:white;text-decoration:none;border-radius:6px;margin-right:12px;">Approve</a>'
            . '<a href="' . self::escape($rejectUrl) . '" style="display:inline-block;padding:12px 24px;background:#ef4444;color:white;text-decoration:none;border-radius:6px;">Reject</a>';

        return self::send([require_env('ADMIN_EMAIL')], $subject, $html);
    }

    public static function sendApprovalEmail(string $email, string $name): bool
    {
        $site = rtrim(config('SITE_URL', base_url()), '/');
        $html = '<h2>Welcome, ' . self::escape($name) . '!</h2>'
            . '<p>Your Home Value wholesale account has been approved.</p>'
            . '<a href="' . self::escape($site . '/en/login') . '" style="display:inline-block;padding:12px 24px;background:#2563eb;color:white;text-decoration:none;border-radius:6px;">Log In Now</a>';

        return self::send([$email], 'Your Home Value Account Has Been Approved!', $html);
    }

    public static function sendRejectionEmail(string $email, string $name): bool
    {
        $html = '<p>Dear ' . self::escape($name) . ',</p>'
            . '<p>Thank you for your interest in Home Value. Unfortunately, we are unable to approve your account at this time.</p>';

        return self::send([$email], 'Home Value Registration Update', $html);
    }

    public static function sendContactForm(array $data): bool
    {
        $subject = 'Contact Form: ' . $data['subject'];
        $html = '<h2>Contact Form Submission</h2>'
            . '<p><strong>From:</strong> ' . self::escape($data['name']) . ' (' . self::escape($data['email']) . ')</p>'
            . '<p><strong>Subject:</strong> ' . self::escape($data['subject']) . '</p>'
            . '<p>' . nl2br(self::escape($data['message'])) . '</p>';

        return self::send([require_env('ADMIN_EMAIL')], $subject, $html, $data['email']);
    }

    /**
     * Cliente mayorista (rol 3): solicitud de contacto con soporte humano vía asistente flotante.
     *
     * @param array{
     *   customerName:string,
     *   customerEmail:string,
     *   customerFvId:int,
     *   lang:string,
     *   pageUrl:string,
     *   message:string
     * } $data
     */
    public static function sendWholesaleSupportEscalation(array $data): bool
    {
        $name = self::escape((string) ($data['customerName'] ?? ''));
        $email = self::escape((string) ($data['customerEmail'] ?? ''));
        $fvId = (int) ($data['customerFvId'] ?? 0);
        $lang = self::escape((string) ($data['lang'] ?? ''));
        $page = self::escape((string) ($data['pageUrl'] ?? ''));
        $msg = nl2br(self::escape((string) ($data['message'] ?? '')));

        $subject = '[Home Value] Wholesale support request — ' . (string) ($data['customerName'] ?? 'customer');
        $html = '<h2>Wholesale customer requested support</h2>'
            . '<table style="border-collapse:collapse;max-width:560px;">'
            . '<tr><td style="padding:6px 0;font-weight:bold;">Name</td><td>' . $name . '</td></tr>'
            . '<tr><td style="padding:6px 0;font-weight:bold;">Email</td><td>' . $email . '</td></tr>'
            . '<tr><td style="padding:6px 0;font-weight:bold;">FV customer_id</td><td>' . $fvId . '</td></tr>'
            . '<tr><td style="padding:6px 0;font-weight:bold;">Language</td><td>' . $lang . '</td></tr>'
            . '<tr><td style="padding:6px 0;font-weight:bold;">Page</td><td>' . $page . '</td></tr>'
            . '</table>'
            . '<h3>Message</h3><p>' . $msg . '</p>';

        $reply = trim((string) ($data['customerEmail'] ?? ''));

        return self::send([require_env('ADMIN_EMAIL')], $subject, $html, $reply !== '' ? $reply : null);
    }
}
