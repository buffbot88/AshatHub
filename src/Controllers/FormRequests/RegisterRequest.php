<?php
declare(strict_types=1);

namespace Controllers\FormRequests;

use Core\AuthService;
use Core\FormRequest;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\FormRequests\RegisterRequest
 * ═══════════════════════════════════════════════════════════════════════
 */
final class RegisterRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'username'     => ['required', 'max:30', function ($val, $addError) {
                $username = trim((string) $val);
                if ($username === '') {
                    return; // 'required' already flagged it
                }
                $error = AuthService::usernameError($username);
                if ($error !== null) {
                    $addError($error);
                }
            }],
            'email'        => ['required', 'email', 'max:255'],
            'password'     => ['required', 'min:8'],
            'display_name' => ['max:100'],
        ];
    }
}
