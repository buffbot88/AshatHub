<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\Mailer — shared-host-friendly email sender (zero dependencies).
 * Uses PHP's built-in mail() with UTF-8 headers; the from-address comes
 * from MAIL_FROM_ADDRESS / MAIL_FROM_NAME (bootstrap defaults or config).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class Mailer
{
    /**
     * Send an HTML email. Returns true when mail() accepted the message.
     */
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        if (!function_exists('mail')) {
            return false;
        }

        $fromAddress = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '';
        $fromName    = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '';

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: ASHAT-Hub/' . (defined('APP_VERSION') ? APP_VERSION : ''),
        ];
        if ($fromAddress !== '') {
            $from = $fromName !== '' ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromAddress}>" : $fromAddress;
            $headers[] = 'From: ' . $from;
            $headers[] = 'Reply-To: ' . $from;
        }

        return @mail($to, self::encodeHeader($subject), $htmlBody, implode("\r\n", $headers));
    }

    /**
     * Encode a header value as UTF-8 base64 (RFC 2047).
     */
    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
