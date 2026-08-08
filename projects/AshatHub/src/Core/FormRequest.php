<?php
declare(strict_types=1);

namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\FormRequest — typed, validated input extraction: every controller
 * action that reads $_POST, $_GET, or php://input should define a
 * subclass instead, eliminating the trim-cast pattern and preventing
 * open-redirect via the safeRedirect() accessor.
 *
 * Usage: $req = LoginRequest::fromGlobals(); then $req->string('username'),
 * $req->failed(), $req->safeRedirect('next', '/'); tests use
 * LoginRequest::fromArray([...]) with no HTTP or $_SESSION involved.
 * ═══════════════════════════════════════════════════════════════════════
 */
abstract class FormRequest
{
    /** Raw input data (from globals or test array). */
    private array $data;

    /** @var array<string, string[]> Field-keyed error messages. */
    private array $errors = [];

    /**
     * Add a validation error for a specific field.
     * Called from subclasses in afterValidation() for cross-field checks.
     */
    protected function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private bool $validated = false;

    /**
     * Subclasses declare validation rules here.
     *
     * Return format:
     *   [
     *       'field_name' => ['required', 'email', 'max:255'],
     *       'another'    => ['required', 'in:admin,pro,guest'],
     *       'optional'   => ['max:100'],
     *   ]
     *
     * Built-in rules: required, email, max:N, min:N, in:a,b,c, url
     * A Closure can also be used as a custom rule:
     *   'field' => [fn($val, $addError) => $val % 2 === 0 ? null : $addError('Must be even')]
     */
    abstract protected function rules(): array;

    /**
     * Optional hook called after all rules run. Useful for cross-field
     * validation (e.g. password === password_confirmation).
     */
    protected function afterValidation(): void {}

    // ── Factories ──────────────────────────────────────────────────

    /**
     * Create from the current HTTP request (POST or JSON body).
     * Detects content type automatically.
     */
    public static function fromGlobals(): static
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $data = str_contains($contentType, 'application/json')
            ? (json_decode(file_get_contents('php://input') ?: '', true) ?: [])
            : $_POST;

        return new static($data);
    }

    /**
     * Create from an explicit array (for testing — no HTTP required).
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    // ── Validation ────────────────────────────────────────────────

    /**
     * Run all rules. Returns true if all fields pass.
     */
    public function validate(): bool
    {
        if ($this->validated) {
            return empty($this->errors);
        }

        $this->errors = [];

        foreach ($this->rules() as $field => $ruleSet) {
            $this->validateField($field, $ruleSet);
        }

        $this->afterValidation();
        $this->validated = true;

        return empty($this->errors);
    }

    /**
     * True if validation failed.
     */
    public function failed(): bool
    {
        return !$this->validate();
    }

    /**
     * Get field-keyed error messages.
     *
     * @return array<string, string[]>
     */
    public function errors(): array
    {
        $this->validate();
        return $this->errors;
    }

    // ── Typed accessors ───────────────────────────────────────────

    /**
     * Get a trimmed string value.
     */
    public function string(string $key, string $default = ''): string
    {
        return trim((string) ($this->data[$key] ?? $default));
    }

    /**
     * Get a string value, returning the default if the field is absent
     * or empty after trimming. Unlike string(), this never returns ''.
     */
    public function filled(string $key, string $default = ''): string
    {
        $val = $this->string($key);
        return $val !== '' ? $val : $default;
    }

    /**
     * Get an integer value.
     */
    public function int(string $key, int $default = 0): int
    {
        return (int) ($this->data[$key] ?? $default);
    }

    /**
     * Get a boolean value (accepts "1", "true", "on", "yes", 1, true).
     */
    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->data[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get an array value.
     */
    public function array(string $key, array $default = []): array
    {
        $val = $this->data[$key] ?? $default;
        return is_array($val) ? $val : $default;
    }

    /**
     * Get a safe redirect URL — only relative paths (starting with /),
     * falling back to $default otherwise. This prevents open-redirect
     * attacks via external URLs like https://evil.com as "next".
     */
    public function safeRedirect(string $key, string $default = '/'): string
    {
        $url = trim((string) ($this->data[$key] ?? $default));
        // Reject: empty, not starting with /, or protocol-relative (//evil.com)
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return $default;
        }
        return $url;
    }

    /**
     * Check if a field is present in the input data.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    // ── Internal validation engine ────────────────────────────────

    /**
     * Run a single field through its rule set.
     *
     * @param string           $field  Field name
     * @param array<int, mixed> $rules  Rule descriptors
     */
    private function validateField(string $field, array $rules): void
    {
        foreach ($rules as $rule) {
            // Parse "max:255" → name="max", param="255"
            $rule  = is_string($rule) ? explode(':', $rule, 2) : [$rule];
            $name  = $rule[0];
            $param = $rule[1] ?? null;

            if (is_callable($name)) {
                // Closure-based rule
                $name($this->data[$field] ?? null, function (string $msg) use ($field): void {
                    $this->errors[$field][] = $msg;
                });
                continue;
            }

            match ($name) {
                'required' => $this->validateRequired($field),
                'max'      => $this->validateMax($field, (int) $param),
                'min'      => $this->validateMin($field, (int) $param),
                'email'    => $this->validateEmail($field),
                'in'       => $this->validateIn($field, explode(',', $param ?? '')),
                'url'      => $this->validateUrl($field),
                default    => null, // unknown rule — skip silently
            };
        }
    }

    private function validateRequired(string $field): void
    {
        $val = $this->data[$field] ?? '';
        if ($val === '' || $val === null || (is_array($val) && $val === [])) {
            $this->errors[$field][] = 'This field is required.';
        }
    }

    private function validateMax(string $field, int $max): void
    {
        $val = $this->data[$field] ?? '';
        if (is_string($val) && strlen($val) > $max) {
            $this->errors[$field][] = "Must be at most {$max} characters.";
        }
    }

    private function validateMin(string $field, int $min): void
    {
        $val = $this->data[$field] ?? '';
        if (is_string($val) && strlen($val) < $min) {
            $this->errors[$field][] = "Must be at least {$min} characters.";
        }
    }

    private function validateEmail(string $field): void
    {
        $val = $this->data[$field] ?? '';
        if ($val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = 'Must be a valid email address.';
        }
    }

    private function validateIn(string $field, array $allowed): void
    {
        $val = $this->data[$field] ?? '';
        if ($val !== '' && !in_array($val, $allowed, true)) {
            $allowedStr = implode(', ', $allowed);
            $this->errors[$field][] = "Must be one of: {$allowedStr}.";
        }
    }

    private function validateUrl(string $field): void
    {
        $val = $this->data[$field] ?? '';
        if ($val !== '' && !str_starts_with($val, 'http://') && !str_starts_with($val, 'https://')) {
            $this->errors[$field][] = 'Must start with http:// or https://.';
        }
    }
}
