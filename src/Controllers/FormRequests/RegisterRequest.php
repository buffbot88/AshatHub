<?php
declare(strict_types=1);

namespace Controllers\FormRequests;

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
            'username'     => ['required', 'max:30'],
            'email'        => ['required', 'email', 'max:255'],
            'password'     => ['required', 'min:8'],
            'display_name' => ['max:100'],
        ];
    }
}
