<?php
declare(strict_types=1);

namespace Controllers\FormRequests;

use Core\FormRequest;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\FormRequests\UpdateProfileRequest
 * ═══════════════════════════════════════════════════════════════════════
 */
final class UpdateProfileRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'display_name' => ['max:200'],
            'email'        => ['email', 'max:255'],
        ];
    }
}
