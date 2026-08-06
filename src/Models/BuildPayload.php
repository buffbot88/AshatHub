<?php
declare(strict_types=1);
namespace Models;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Models\BuildPayload — validated agent build metadata: a value object
 * that either holds a valid plan + file_paths list, or an error message.
 * Encapsulates validation that was previously embedded in
 * BuildsController::acceptAgentBuild(); constructed via fromRequest().
 * ═══════════════════════════════════════════════════════════════════════
 */
final class BuildPayload
{
    private const MAX_FILES = 500;
    private const MAX_FILE_BYTES = 250 * 1024;  // 250 KB

    private string $plan;
    /** @var list<array{path:string, language:string, size_bytes:int}> */
    private array $paths;
    private ?string $error;

    /**
     * @param string                                          $plan  Agent's build plan
     * @param list<array{path?:string, language?:string, size_bytes?:int}> $paths Raw file entries
     */
    private function __construct(string $plan, array $paths)
    {
        $error = self::validate($plan, $paths);
        if ($error !== null) {
            $this->error = $error;
            $this->plan = '';
            $this->paths = [];
            return;
        }

        $this->plan = $plan;
        $this->paths = self::cleanPaths($paths);
        $this->error = null;
    }

    /**
     * Factory: create from raw agent payload data.
     */
    public static function fromRequest(string $plan, array $paths): self
    {
        return new self($plan, $paths);
    }

    /**
     * Did validation fail?
     */
    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * Error message (only meaningful if failed() is true).
     */
    public function error(): string
    {
        return $this->error ?? '';
    }

    /**
     * Validated plan text.
     */
    public function plan(): string
    {
        return $this->plan;
    }

    /**
     * Validated file path entries.
     *
     * @return list<array{path:string, language:string, size_bytes:int}>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    // ── Internal validation ─────────────────────────────────────────

    /**
     * Run all validation rules (plan-level + per-file).
     * Returns an error string or null on success.
     */
    private static function validate(string $plan, array $paths): ?string
    {
        if ($plan === '') {
            return 'agent plan is empty';
        }
        if (count($paths) === 0) {
            return 'agent returned no files';
        }
        if (count($paths) > self::MAX_FILES) {
            return 'too many files (cap ' . self::MAX_FILES . ')';
        }

        foreach ($paths as $i => $f) {
            if (!is_array($f)) {
                return "file #{$i} not an object";
            }

            $path = isset($f['path']) ? (string) $f['path'] : '';
            $size = isset($f['size_bytes']) ? (int) $f['size_bytes'] : 0;

            $sanitized = self::sanitizePath($path);
            if ($sanitized === '') {
                return "file #{$i} has invalid path";
            }
            if ($size > self::MAX_FILE_BYTES) {
                return "file {$sanitized} exceeds " . (self::MAX_FILE_BYTES / 1024) . 'KB cap';
            }
        }

        return null;
    }

    /**
     * Sanitize each file entry and detect language.
     * Only called after validate() passes, so no validation checks needed.
     *
     * @param list<array{path?:string, language?:string, size_bytes?:int}> $paths
     * @return list<array{path:string, language:string, size_bytes:int}>
     */
    private static function cleanPaths(array $paths): array
    {
        $clean = [];
        foreach ($paths as $f) {
            $path = self::sanitizePath((string) ($f['path'] ?? ''));

            $lang = isset($f['language']) && $f['language'] !== ''
                ? (string) $f['language']
                : \Core\LanguageDetector::detect($path);

            $clean[] = [
                'path'       => $path,
                'language'   => $lang,
                'size_bytes' => (int) ($f['size_bytes'] ?? 0),
            ];
        }
        return $clean;
    }

    /**
     * Normalize a file path: backslash→slash, strip leading /, remove
     * directory-traversal (..) runs, strip control characters.
     */
    private static function sanitizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        $path = preg_replace('/\.\.+/', '', $path) ?? $path;
        $path = preg_replace('#/+#', '/', $path) ?? $path;   // collapse slash runs
        $path = trim($path, '/');
        $path = preg_replace('/[\x00-\x1f]/', '', $path) ?? $path;
        return $path;
    }
}
