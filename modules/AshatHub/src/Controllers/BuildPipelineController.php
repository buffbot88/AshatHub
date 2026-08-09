<?php
declare(strict_types=1);
namespace Controllers;

use Core\ConfigBag;
use Core\RequestContext;
use Core\SseStreamer;
use Core\SystemValidationEngine;
use Models\ChatBackend;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\BuildPipelineController — server-side build pipeline.
 *
 * The chat AI (350M on the local Intent Router) gathers context and builds
 * the spec. When the user approves file generation, the browser POSTs the
 * spec here; this controller hands the complete build plan to the remote
 * BrainStem Neural Host (Omega), which generates the code. The generated
 * files then flow back through the local System Validation Engine — the
 * 350M debugs/validates each file, the VL model visually checks front-end
 * files — and the validated results are written straight into Project
 * Files server-side.
 *
 * Streams progress as SSE so the chat can show "Generating…",
 * "Validating project.", "Visual check…" in real time.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class BuildPipelineController
{
    /** Per-account storage quota (bytes) — matches FilesController. */
    private const QUOTA_BYTES = 150 * 1024 * 1024;
    /** Per-file cap (bytes) — mirrors agent.js defense-in-depth. */
    private const MAX_FILE_BYTES = 250 * 1024;
    /** Total build cap (bytes). */
    private const MAX_TOTAL_BYTES = 5 * 1024 * 1024;

    /**
     * POST /api/build/pipeline/ — SSE. Body: { spec, plan?, language? }.
     * Never emits code to the browser mid-stream; only progress + done.
     */
    public function pipeline(RequestContext $ctx): void
    {
        // Builds span generation + per-file validation + a visual check;
        // lift the FPM default (120 s) so long builds are not cut off.
        if (function_exists('set_time_limit')) {
            set_time_limit(900);
        }
        SseStreamer::headers();
        try {
            $this->runPipeline($ctx);
        } catch (\Throwable $e) {
            // Safety net: if anything throws after SSE headers are sent,
            // always emit an error event so the client doesn't hang.
            try {
                SseStreamer::send('error', ['message' => 'Pipeline failed: ' . $e->getMessage()]);
            } catch (\Throwable $ignored) {
                // SSE stream already closed — nothing we can do.
            }
        }
    }

    /**
     * Core pipeline logic — extracted so the outer try/catch in pipeline()
     * can always send an SSE error event on failure.
     */
    private function runPipeline(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $spec = trim((string) ($body['spec'] ?? ''));
        if ($spec === '') {
            SseStreamer::send('error', ['message' => 'spec_required']);
            return;
        }

        $brainstem = RepositoryRegistry::brainstemConfig()->active();
        if (empty($brainstem['api_key'])) {
            SseStreamer::send('error', ['message' => 'No BrainStem host is configured for code generation.']);
            return;
        }

        $routerUrl = ConfigBag::getInstance()->intentRouterUrl();
        $userId    = (string) $ctx->user()['id'];

        // Build mode is gated: brainstorm first — both docs must exist on disk.
        $projDir = ashat_user_project_dir($userId);
        if (!is_file($projDir . '/Spec.md') || !is_file($projDir . '/Build.md')) {
            SseStreamer::send('error', [
                'message' => 'build_locked: brainstorm first — Spec.md and Build.md must exist in your project files.',
            ]);
            return;
        }

        // Meta event — the status pill lists every model in the pipeline.
        $models = [];
        if (trim((string) $routerUrl) !== '') {
            $models[] = ChatBackend::defaultIntentRouterLabel();
            $models[] = ChatBackend::defaultVisionLabel();
        }
        $bsModel = trim((string) ($brainstem['model'] ?? ''));
        $models[] = $bsModel !== '' ? $bsModel : ChatBackend::defaultBrainstemLabel();
        SseStreamer::send('meta', [
            'model'   => end($models) ?: ChatBackend::defaultBrainstemLabel(),
            'backend' => 'pipeline',
            'models'  => $models,
        ]);

        // ── Phase 1: BrainStem generates the code ────────────────────
        SseStreamer::send('progress', ['message' => 'Generating project files…']);
        // BrainStem requires the exact GGUF model id in the 'model' field.
        // The admin config may leave it empty; probe the models endpoint to
        // discover the actual name before constructing the backend request.
        if (empty($brainstem['model'])) {
            $brainstem['model'] = $this->probeBrainstemModel($brainstem['url']);
        }

        $backend = ChatBackend::select($brainstem, null, '');
        if (!$backend->isAvailable()) {
            SseStreamer::send('error', ['message' => 'BrainStem host is not configured.']);
            return;
        }

        $messages = $this->generationMessages($body);
        $req = $backend->buildRequest($messages, ['max_tokens' => 16384, 'temperature' => 0.6], false);
        $resp = SystemValidationEngine::postJson($req['endpoint'], $req['headers'], $req['payload']);
        if ($resp === null) {
            SseStreamer::send('error', ['message' => 'BrainStem did not respond. Check the Neural Host and try again.']);
            return;
        }
        $content = $resp['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            SseStreamer::send('error', ['message' => 'BrainStem returned an empty generation.']);
            return;
        }

        $parsed = SystemValidationEngine::lenientJson($content);
        if (!is_array($parsed) || empty($parsed['files']) || !is_array($parsed['files'])) {
            SseStreamer::send('error', ['message' => 'BrainStem did not return a valid build payload (expected {plan, files[]}).']);
            return;
        }

        $files = [];
        foreach ($parsed['files'] as $f) {
            if (!is_array($f) || !isset($f['path']) || !isset($f['content'])) continue;
            $path = $this->sanitizePath((string) $f['path']);
            if ($path === '') continue;
            $contentStr = (string) $f['content'];
            if (strlen($contentStr) > self::MAX_FILE_BYTES) continue;
            $files[] = [
                'path'     => $path,
                'content'  => $contentStr,
                'language' => \Core\LanguageDetector::detect($path),
            ];
        }
        if (empty($files)) {
            SseStreamer::send('error', ['message' => 'BrainStem returned no usable files.']);
            return;
        }
        $totalBytes = array_sum(array_map('strlen', array_column($files, 'content')));
        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            SseStreamer::send('error', ['message' => 'Generated project exceeds the 5 MB build cap.']);
            return;
        }

        $planText = trim((string) ($parsed['plan'] ?? '')) ?: 'Built ' . count($files) . ' file(s) from your spec.';

        // ── Phase 2: 350M debug pass per file ────────────────────────
        $validated = [];
        $frontEnd  = [];
        $allIssues = [];
        $hasRouter = trim((string) $routerUrl) !== '';
        if ($hasRouter) {
            SseStreamer::send('progress', ['message' => 'Validating project.']);
            foreach ($files as $f) {
                SseStreamer::send('progress', ['message' => 'Validating ' . $f['path'] . '…']);
                $static = SystemValidationEngine::staticCheck($f['path'], $f['content']);
                $dbg = SystemValidationEngine::debugFile(
                    $f['path'],
                    $f['content'],
                    (string) $routerUrl,
                    implode('; ', $static['issues'])
                );
                $issues = array_merge($static['issues'], $dbg['issues']);
                $f['content'] = $dbg['content'];
                $f['issues']  = $issues;
                $f['valid']   = count($issues) === 0;
                foreach ($issues as $issue) $allIssues[] = $f['path'] . ': ' . $issue;
                $validated[] = $f;
                if (preg_match('/\.(html?|css|js|mjs)$/i', $f['path']) === 1) $frontEnd[] = $f;
            }
        } else {
            foreach ($files as $f) {
                $f['issues'] = [];
                $f['valid']  = true;
                $validated[] = $f;
                if (preg_match('/\.(html?|css|js|mjs)$/i', $f['path']) === 1) $frontEnd[] = $f;
            }
        }

        // ── Phase 3: VL visual check (front-end files) ──────────────
        if ($hasRouter && !empty($frontEnd)) {
            SseStreamer::send('progress', ['message' => 'Visual check…']);
            // Give the kernel a moment to swap out idle pages before
            // Chromium launches — this box runs two llama-servers and the
            // FPM worker has accumulated memory from the validation pass.
            if (function_exists('gc_collect_cycles')) gc_collect_cycles();
            usleep(500_000); // 0.5 s pause for swap
            $workDir = sys_get_temp_dir() . '/ashat-visual-' . bin2hex(random_bytes(4));
            @mkdir($workDir, 0775, true);
            $vis = SystemValidationEngine::visualCheck($frontEnd, $workDir, (string) $routerUrl);
            self::cleanDir($workDir);
            if (!$vis['ok'] && $vis['findings'] !== '') {
                $allIssues[] = 'Visual: ' . trim($vis['findings']);
            }
            SseStreamer::send('progress', ['message' => 'Visual check complete.']);
        }

        // ── Phase 4: write validated files server-side ──────────────
        SseStreamer::send('progress', ['message' => 'Writing files into your Project Files…']);
        $repo     = RepositoryRegistry::file();
        $usage    = $repo->totalBytes($userId);
        $quotaHit = false;
        $saved    = 0;
        $savedPaths = [];
        foreach ($validated as $f) {
            $existing = $repo->findByPath($userId, $f['path']);
            $delta = strlen($f['content']) - (int) strlen((string) ($existing['content'] ?? ''));
            if ($usage + $delta > self::QUOTA_BYTES) {
                $quotaHit = true;
                continue;
            }
            $usage += $delta;
            try {
                $repo->save($userId, $f['path'], $f['content'], $f['language'], true);
                $saved++;
                $savedPaths[] = ['path' => $f['path'], 'language' => $f['language']];
            } catch (\Throwable $e) {
                $allIssues[] = $f['path'] . ': could not save (' . $e->getMessage() . ')';
            }
        }

        SseStreamer::send('done', [
            'files'     => $savedPaths,
            'saved'     => $saved,
            'plan'      => $planText,
            'issues'    => array_slice($allIssues, 0, 40),
            'quota_hit' => $quotaHit,
        ]);
    }

    /**
     * Probe BrainStem's /v1/models to discover the exact GGUF id when
     * the admin config left the model field empty.
     */
    private function probeBrainstemModel(string $url): string
    {
        $resp = SystemValidationEngine::getJson(
            rtrim($url, '/') . '/v1/models'
        );
        if (isset($resp['data'][0]['id']) && $resp['data'][0]['id'] !== '') {
            return (string) $resp['data'][0]['id'];
        }
        // No hardcoded fallback — if the probe fails, return empty and
        // let the caller handle the missing model gracefully.
        return '';
    }

    /** Build the code-generation messages for BrainStem. */
    private function generationMessages(array $body): array
    {
        $spec  = trim((string) ($body['spec'] ?? ''));
        $plan  = trim((string) ($body['plan'] ?? ''));
        $lang  = trim((string) ($body['language'] ?? ''));

        $system = [
            'You are ASHAT, an AI coding agent that builds software from markdown specifications.',
            '',
            'Given a user spec, you must:',
            '1. Analyze the spec and create a concise build plan.',
            '2. Generate all the necessary files with complete, working code.',
            '3. Return a single JSON object with EXACTLY this shape:',
            '',
            '{',
            '  "plan": "A 2-4 sentence summary of what you will build.",',
            '  "files": [',
            '    {',
            '      "path":     "relative/file/path.ext",',
            '      "content":  "the complete file contents",',
            '      "language": "the programming language"',
            '    }',
            '  ]',
            '}',
            '',
            'Rules:',
            '- Generate production-ready code with sensible error handling.',
            '- Use file paths relative to the project root (no leading slash).',
            '- Include a README.md describing how to install/run/verify.',
            '- Do NOT include private keys, real credentials, or secrets.',
            '- Output ONLY the JSON object. No prose, no code fences.',
        ];

        $user = 'Build the following specification:\n\n' . $spec;
        if ($plan !== '') {
            $user .= "\n\nThe user approved this build plan — follow it exactly:\n" . $plan;
        }
        if ($lang !== '') {
            $user .= "\n\nIMPORTANT: Build this project in " . $lang . '.';
        }

        return [
            ['role' => 'system', 'content' => implode("\n", $system)],
            ['role' => 'user',   'content' => $user],
        ];
    }

    /** Normalize a generated path: no traversal, no leading slash. */
    private function sanitizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);        // normalize backslashes
        $path = preg_replace('/^\.?\//', '', $path);  // strip leading slashes or ./
        // Strip traversal segments: match ".." as a whole path component
        $path = preg_replace('/(?:^|\/)\.\.($|\/)/', '/', $path);
        $path = preg_replace('/\/{2,}/', '/', $path);  // collapse doubles
        $path = preg_replace('/[\x00-\x1f]/', '', $path); // strip control chars
        return trim($path, '/');
    }

    /** Recursively remove a scratch directory. */
    private static function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
