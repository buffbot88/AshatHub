<?php
declare(strict_types=1);

namespace Controllers\FormRequests;

use Core\FormRequest;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\FormRequests\LoginRequest
 * ═══════════════════════════════════════════════════════════════════════
 */
final class LoginRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'username' => ['required'],
            'password' => ['required'],
            // 'next' is intentionally NOT in rules — it's read via safeRedirect()
        ];
    }
}
