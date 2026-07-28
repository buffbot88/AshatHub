<?php
declare(strict_types=1);

namespace Controllers\FormRequests;

use Core\FormRequest;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\FormRequests\SessionAuthRequest
 *
 * Desktop client login (popup window → callback redirect).
 * The callback URL is provided by the desktop client and is intentionally
 * an external URL — validated separately in the controller to ensure it
 * looks like a valid absolute URL (has a scheme).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class SessionAuthRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'username' => ['required'],
            'password' => ['required'],
        ];
    }
}
