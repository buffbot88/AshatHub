<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\SystemValidationEngine — server-side debug + validation for
 * generated project files (the build pipeline's QA layer).
 *
 * Responsibilities:
 *  1. Static syntax gates per language (node --check, php -l, JSON/Python
 *     parses) — cheap, deterministic catches before any model runs.
 *  2. 350M debug pass — sends each file to the local Intent Router for a
 *     bug/syntax/security review; adopts the model's corrected version
 *     only when it returns a real code fence, otherwise keeps the file
 *     and records the issues.
 *  3. Visual check — renders front-end files with headless Chromium,
 *     screenshots them, and has the VL model review the image so the
 *     front-end "looks" sound, not just parses.
 *
 * All model calls are best-effort: the pipeline degrades gracefully
 * (keeps the original file + a note) instead of failing the build.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class SystemValidationEngine
{
    // ── Static syntax gates ─────────────────────────────────────────

    /**
     * Run deterministic syntax checks for a file's language.
     * Returns ['ok' => bool, 'issues' => string[]].
     */
    public static function staticCheck(string $path, string $content): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $issues = [];

        switch ($ext) {
            case 'js':
            case 'mjs':
            case 'cjs':
                $issues = self::nodeSyntaxCheck($content);
                break;
            case 'json':
                if (json_decode($content) === null && trim($content) !== '') {
                    $issues[] = 'Invalid JSON: ' . json_last_error_msg();
                }
                break;
            case 'php':
                $issues = self::phpSyntaxCheck($content);
                break;
            case 'py':
                $issues = self::pythonSyntaxCheck($content);
                break;
            case 'html':
            case 'htm':
                $issues = self::htmlBalanceCheck($content);
                break;
            default:
                break; // no static gate for css/others — the 350M pass covers them
        }

        return ['ok' => count($issues) === 0, 'issues' => $issues];
    }

    /** node --check on a temp file (JS only). Returns string[] issues. */
    private static function nodeSyntaxCheck(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ashat-js-');
        if ($tmp === false) return [];
        try {
            file_put_contents($tmp, $content);
            $out = [];
            $code = 0;
            exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            return $code === 0 ? [] : [trim(implode("\n", array_slice($out, 0, 4)))];
        } finally {
            @unlink($tmp);
        }
    }

    /** php -l on a temp file. Returns string[] issues. */
    private static function phpSyntaxCheck(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ashat-php-');
        if ($tmp === false) return [];
        try {
            file_put_contents($tmp, $content);
            $out = [];
            $code = 0;
            exec(PHP_BINARY . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            return $code === 0 ? [] : [trim(implode("\n", array_slice($out, 0, 4)))];
        } finally {
            @unlink($tmp);
        }
    }

    /** python3 -m py_compile on a temp file. Returns string[] issues. */
    private static function pythonSyntaxCheck(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ashat-py-');
        if ($tmp === false) return [];
        try {
            file_put_contents($tmp, $content);
            $out = [];
            $code = 0;
            exec('python3 -m py_compile ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            return $code === 0 ? [] : [trim(implode("\n", array_slice($out, 0, 4)))];
        } finally {
            @unlink($tmp);
            if (is_file($tmp . 'c')) @unlink($tmp . 'c');
            if (is_file($tmp . '.pyc')) @unlink($tmp . '.pyc');
        }
    }

    /** Lightweight HTML tag-balance check for common structural tags. */
    private static function htmlBalanceCheck(string $content): array
    {
        $issues = [];
        foreach (['div', 'section', 'main', 'header', 'footer', 'nav', 'ul', 'ol', 'table', 'form', 'script', 'style'] as $tag) {
            $open  = preg_match_all('/<' . $tag . '(\s[^>]*)?(?<!\/)>/i', $content);
            $close = preg_match_all('/<\/' . $tag . '>/i', $content);
            if ($open !== $close) {
                $issues[] = "Unbalanced <$tag> tags ($open open / $close close).";
            }
        }
        return $issues;
    }

    // ── 350M debug pass ─────────────────────────────────────────────

    /**
     * Send one file to the local Intent Router's 350M for a code review.
     * Adopts the corrected file only when the model returns a real code
     * fence; otherwise keeps the original and records the issues.
     *
     * @return array{content:string, issues:string[]}
     */
    public static function debugFile(
        string $path,
        string $content,
        string $intentRouterUrl,
        string $fallbackIssue = ''
    ): array {
        $url = rtrim($intentRouterUrl, '/') . '/v1/chat';
        $prompt = "You are the System Validation Engine. Review this file for bugs, "
            . "syntax errors, and security issues.\n"
            . "Reply with a single line: PASS if the file is correct.\n"
            . "If it needs fixes, reply ONLY with the corrected complete file inside "
            . "a ``` code fence (no prose).\n\n"
            . "FILE: $path\n\n"
            . "```\n" . $content . "\n```";

        $payload = [
            'messages'    => [
                ['role' => 'system', 'content' => 'You are Ashat\'s System Validation Engine. Be precise and terse.'],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens'  => 2048,
        ];

        $body = self::postJson($url, ['Content-Type: application/json'], $payload);
        $text = self::extractContent($body);
        if ($text === '') {
            return ['content' => $content, 'issues' => $fallbackIssue !== '' ? [$fallbackIssue] : ['Debug pass returned nothing — file kept unchanged.']];
        }

        // PASS → keep original.
        if (preg_match('/^\s*PASS\b/i', trim($text)) === 1) {
            return ['content' => $content, 'issues' => []];
        }

        // Extract the corrected file from a code fence.
        $fence = self::extractFence($text);
        if ($fence !== null && strlen($fence) > 20) {
            return ['content' => $fence, 'issues' => []];
        }

        // Non-PASS prose but no usable fence — keep original, note it.
        $note = trim(preg_replace('/\s+/', ' ', $text));
        $note = mb_substr($note, 0, 300);
        return [
            'content' => $content,
            'issues'  => ['Debug pass: ' . ($note !== '' ? $note : 'needs review')],
        ];
    }

    /**
     * Pull the first substantial fenced block out of a model reply.
     * Returns null when there is no fence (or it is just the echo of
     * the prompt's own fence).
     */
    private static function extractFence(string $text): ?string
    {
        if (!preg_match('/```[a-zA-Z0-9_+-]*\s*\n([\s\S]*?)```/', $text, $m)) {
            return null;
        }
        $code = $m[1];
        // A fence that merely echoes the prompt's input (same first line)
        // is not a fix — treat as no-fix.
        $code = preg_replace('/^FILE:.*\n?/m', '', $code);
        return trim($code);
    }

    // ── Visual check (headless render → VL review) ─────────────────

    /**
     * Render the front-end of a generated project to a screenshot and
     * have the VL model review it. Returns the VL findings.
     *
     * @param array  $frontEndFiles Files to stage for rendering
     * @param string $workDir       Scratch dir the caller created
     * @return array{ok:bool, findings:string}
     */
    public static function visualCheck(
        array $frontEndFiles,
        string $workDir,
        string $intentRouterUrl
    ): array {
        // Find an entry html (prefer index.html).
        $entry = null;
        foreach ($frontEndFiles as $f) {
            if (preg_match('/\/(index|main)\.html$/i', $f['path']) === 1) {
                $entry = $f['path'];
                break;
            }
        }
        if ($entry === null) {
            foreach ($frontEndFiles as $f) {
                if (preg_match('/\.html$/i', $f['path']) === 1) { $entry = $f['path']; break; }
            }
        }
        if ($entry === null) {
            return ['ok' => false, 'findings' => 'No HTML entry point to render.'];
        }

        // Stage files under the work dir mirroring their relative paths.
        foreach ($frontEndFiles as $f) {
            $rel  = ltrim($f['path'], '/');
            $dest = $workDir . '/' . $rel;
            $dir  = dirname($dest);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            file_put_contents($dest, $f['content']);
        }

        $entryPath = $workDir . '/' . ltrim($entry, '/');
        $png       = $workDir . '/preview.png';
        // Tools live at /opt/ashat-visual (SELinux-visible from httpd_t).
        // A wrapper script sets PLAYWRIGHT_BROWSERS_PATH + HOME and exec's node.
        $cmd = '/opt/ashat-visual/run-screenshot.sh '
            . escapeshellarg($entryPath) . ' ' . escapeshellarg($png)
            . ' 2>&1';

        // Retry up to 2 times — Chromium can fail under memory pressure
        // (ENOMEM from fork) on a box running two llama-servers.
        $out = [];
        $code = 0;
        $lastDetail = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $out = [];
            $code = 0;
            exec($cmd, $out, $code);
            if ($code === 0 && is_file($png)) break;
            $lastDetail = trim(implode(' ', array_slice($out, 0, 6)));
            if ($lastDetail === '') $lastDetail = '(no output; exit ' . $code . ')';
            if ($attempt < 2) {
                gc_collect_cycles();
                usleep(1_000_000); // 1 s pause before retry
            }
        }
        if ($code !== 0 || !is_file($png)) {
            return ['ok' => false, 'findings' => 'Headless render failed: ' . $lastDetail];
        }

        $b64 = base64_encode((string) file_get_contents($png));
        $url = rtrim($intentRouterUrl, '/') . '/v1/vision';
        $payload = [
            'messages'    => [
                ['role' => 'user', 'content' => 'This is a screenshot of a generated web page. Check for visual problems: broken layout, overlapping text, clipped content, empty sections, or obviously broken styling. Be terse.'],
            ],
            'image'       => $b64,
            'temperature' => 0.2,
            'max_tokens'  => 512,
        ];
        $body = self::postJson($url, ['Content-Type: application/json'], $payload);
        $findings = self::extractContent($body);

        if ($findings === '') {
            return ['ok' => false, 'findings' => 'VL review returned nothing.'];
        }
        // Advisory: a description without explicit problem words is a pass.
        // Only explicit breakage words fail the visual check.
        $problem = preg_match(
            '/\b(?:broken|overlap|clipped|cut off|not (?:rendering|visible|showing)|missing|empty (?:section|page)|error|failed to load|misaligned|overlapping|garbled|blank page|no styling)\b/i',
            $findings
        ) === 1;
        return ['ok' => !$problem, 'findings' => trim($findings)];
    }

    // ── Lenient JSON recovery for model output ─────────────────────

    /**
     * Try to decode a model reply as JSON: direct parse first, then the
     * first balanced {...} block anywhere in the text. Returns null on
     * total failure. Mirrors agent.js extractJson() in spirit.
     */
    public static function lenientJson(string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') return null;

        $direct = json_decode($text, true);
        if (is_array($direct)) return $direct;

        // Strip ```json fences if present.
        if (preg_match('/```(?:json)?\s*\n([\s\S]*?)(?:```|$)/', $text, $m)) {
            $direct = json_decode(trim($m[1]), true);
            if (is_array($direct)) return $direct;
        }

        // First balanced {...} block (string-aware, naive).
        $depth = 0;
        $inStr = false;
        $esc = false;
        $start = -1;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($esc) { $esc = false; continue; }
            if ($inStr) {
                if ($ch === '\\') { $esc = true; }
                elseif ($ch === '"') { $inStr = false; }
                continue;
            }
            if ($ch === '"') { $inStr = true; continue; }
            if ($ch === '{') {
                if ($start === -1) $start = $i;
                $depth++;
            } elseif ($ch === '}' && $start !== -1) {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    $parsed = json_decode($candidate, true);
                    if (is_array($parsed)) return $parsed;
                    $start = -1;
                }
            }
        }
        return null;
    }

    // ── Upstream helpers ────────────────────────────────────────────

    /** POST JSON, return the decoded array or null. Best-effort, one try. */
    public static function postJson(string $endpoint, array $headers, array $payload): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $headers,
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout'       => 120,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($endpoint, false, $ctx);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** GET a URL and decode the JSON response. */
    public static function getJson(string $url): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Pull the assistant text out of an OpenAI-style response. */
    private static function extractContent(?array $body): string
    {
        if (!is_array($body)) return '';
        if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        return '';
    }
}
