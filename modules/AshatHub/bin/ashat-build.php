#!/usr/bin/env php
<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — Chat Studio Build mode CLI (bin/ashat-build.php)
 *
 * Rebuilds the browser-only Chat Studio "Build" tab as a terminal tool.
 * The web flow (assistant.js → /api/brainstorm/ + /api/build/pipeline/)
 * becomes three commands:
 *
 *   brainstorm <idea…>   Idea → Spec.md + Build.md via the LOCAL 450M VL
 *                        (intent router). Mirrors BrainstormController.
 *   build <idea…> [opts] Generate code from a natural-language idea or an
 *                        existing spec (NO Spec.md/Build.md gate), run the
 *                        System Validation Engine (static gates + 350M
 *                        debug pass + VL visual check), and write the
 *                        files straight into the project directory.
 *                        Mirrors Controllers\BuildPipelineController.
 *   status [opts]        Print project-doc state (informational — no gate).
 *
 * Model roles: the local 450M VL (Intent Router) does brainstorming and
 * intent summarization; Omega/Beta/Delta (BrainStem Neural Host) are code
 * agents only — they never see chat or spec-writing prompts.
 *
 * Reuse over rebuild: all the heavy lifting comes from the web code —
 * Core\SystemValidationEngine (staticCheck / debugFile / visualCheck /
 * lenientJson / postJson / getJson), Models\ChatBackend (backend selection
 * + request construction), Core\ConfigBag (env/config resolution) and
 * Core\LanguageDetector. Only the orchestration is reimplemented here so
 * the CLI can print plain progress instead of SSE frames.
 *
 * Usage:
 *   php bin/ashat-build.php brainstorm "A tiny static HTML site…" [--out DIR]
 *   php bin/ashat-build.php build [--out DIR] [--spec FILE] [--plan FILE]
 *                                [--language LANG] [--no-debug] [--no-visual]
 *                                [--json]
 *   php bin/ashat-build.php status [--out DIR]
 *
 * Config: BRAINSTEM_URL / BRAINSTEM_KEY / INTENT_ROUTER_URL come from
 * config/server_config.json, then .env, then defaults — identical to the
 * web bootstrap. --url/--key/--model override per invocation.
 * ═══════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

// ═════════════════════════════════════════════════════════════════════
//  CLI BOOTSTRAP — env + autoloader + ConfigBag (no session, no HTTP)
// ═════════════════════════════════════════════════════════════════════

$moduleRoot = dirname(__DIR__);
define('ASHAT_ROOT', $moduleRoot);
define('ASHAT_PUBLIC', $moduleRoot . '/public');

// Config resolution mirrors config/bootstrap.php: server_config.json is
// authoritative, .env is the fallback. Keys land in $_ENV so ConfigBag
// and getenv() both see them.
function ashat_cli_load_json_config(string $path): bool
{
    if (!is_file($path)) return false;
    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json)) return false;
    foreach ($json as $k => $v) {
        if (str_starts_with((string) $k, '//')) continue;
        if (!is_scalar($v)) continue;
        $_ENV[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        @putenv("$k={$_ENV[$k]}");
    }
    return true;
}

function ashat_cli_load_env(string $path): void
{
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (!array_key_exists($k, $_ENV)) {
            $_ENV[$k] = trim($v, " \t\"'");
            @putenv("$k={$_ENV[$k]}");
        }
    }
}

if (!ashat_cli_load_json_config(ASHAT_ROOT . '/config/server_config.json')) {
    ashat_cli_load_env(ASHAT_ROOT . '/.env');
}

// Same PSR-4-ish prefix map as config/bootstrap.php — CLI only needs Core
// + Models, but the full map keeps future reuse free.
spl_autoload_register(function (string $class) use ($moduleRoot): void {
    $prefixMap = [
        'Core\\'         => $moduleRoot . '/src/Core/',
        'Models\\'       => $moduleRoot . '/src/Models/',
        'Controllers\\'  => $moduleRoot . '/src/Controllers/',
        'Repositories\\' => $moduleRoot . '/src/Repositories/',
        'Data\\'         => $moduleRoot . '/src/Data/',
    ];
    foreach ($prefixMap as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; return; }
        }
    }
});

\Core\ConfigBag::setInstance(new \Core\ConfigBag(
    rtrim((string) ($_ENV['BRAINSTEM_URL'] ?? getenv('BRAINSTEM_URL') ?: 'http://localhost:7860'), '/'),
    (string) ($_ENV['BRAINSTEM_KEY'] ?? getenv('BRAINSTEM_KEY') ?: ''),
    rtrim((string) ($_ENV['INTENT_ROUTER_URL'] ?? getenv('INTENT_ROUTER_URL') ?: 'http://127.0.0.1:3000'), '/')
));

if (function_exists('set_time_limit')) {
    set_time_limit(900); // builds span generation + per-file validation + visual check
}

// ═════════════════════════════════════════════════════════════════════
//  TINY HELPERS
// ═════════════════════════════════════════════════════════════════════

/** Write a progress line to stderr (stdout stays clean for --json). */
function out(string $msg): void
{
    $msg = trim($msg);
    if ($msg === '') { fwrite(STDERR, "\n"); return; }
    fwrite(STDERR, "  " . $msg . "\n");
}

/** Print a step header. */
function step(string $msg): void
{
    fwrite(STDERR, "\n── " . $msg . "\n");
}

/** Die with a message + exit code. */
function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "\n✖ " . $msg . "\n");
    exit($code);
}

/** Resolve the BrainStem host config: per-invocation flags > env/config. */
function resolveBrainstem(array $opts): array
{
    $config = \Core\ConfigBag::getInstance();
    return [
        'url'     => rtrim((string) ($opts['url'] ?? $config->brainstemUrl()), '/'),
        'api_key' => (string) ($opts['key'] ?? $config->brainstemKey()),
        'model'   => trim((string) ($opts['model'] ?? '')),
    ];
}

/**
 * Probe BrainStem's /v1/models to discover the exact GGUF id when the
 * config left the model field empty. The host lists the VL intent
 * classifier first, so prefer a model whose purpose is inference/coding
 * (the code generator) and fall back to the first entry. The web probe
 * takes data[0]; this is the same probe, but id-aware.
 */
function probeBrainstemModel(string $url): string
{
    $resp = \Core\SystemValidationEngine::getJson(rtrim($url, '/') . '/v1/models');
    if (isset($resp['data']) && is_array($resp['data'])) {
        foreach ($resp['data'] as $m) {
            $purpose = strtolower((string) ($m['purpose'] ?? ''));
            $id = (string) ($m['id'] ?? '');
            if ($id !== '' && (str_contains($purpose, 'inference') || str_contains($purpose, 'coding'))) {
                return $id;
            }
        }
        if (isset($resp['data'][0]['id']) && $resp['data'][0]['id'] !== '') {
            return (string) $resp['data'][0]['id'];
        }
    }
    return '';
}

/** Whether the local Intent Router (alpha-server) is up. */
function intentRouterUp(): bool
{
    $url = rtrim(\Core\ConfigBag::getInstance()->intentRouterUrl(), '/') . '/health';
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return false;
    $decoded = json_decode($raw, true);
    return is_array($decoded) && ($decoded['status'] ?? '') === 'ok';
}

/**
 * Normalize a generated path — mirror of BuildPipelineController: no
 * traversal, no leading slash, collapsed separators, no control chars.
 */
function sanitizePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('/^\.?\//', '', $path) ?? $path;
    $path = preg_replace('/(?:^|\/)\.\.($|\/)/', '/', $path) ?? $path;
    $path = preg_replace('/\/{2,}/', '/', $path) ?? $path;
    $path = preg_replace('/[\x00-\x1f]/', '', $path) ?? $path;
    return trim($path, '/');
}

/**
 * One streaming POST round-trip through Omega; returns the accumulated
 * message text, or null on failure. CLI twin of SseStreamer::proxy
 * (which echoes SSE frames — wrong for a terminal) with the same
 * retry/backoff policy.
 *
 * Streaming beats the non-streaming path here: the web pipeline's plain
 * POST times out at 120 s on long generations, so even a short build can
 * die with "BrainStem did not respond". Token deltas keep the socket
 * alive for arbitrarily long generations.
 */
function streamCompletion(string $endpoint, array $headers, array $payload): ?array
{
    $attempt = 0;
    $tokenRetried = false;

    while (true) {
        $attempt++;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $headers,
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout'       => 120,
                'ignore_errors' => true,
            ],
        ]);
        $stream = @fopen($endpoint, 'r', false, $ctx);
        if (!$stream) return null;

        $status = 0;
        if (preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m)) $status = (int) $m[1];

        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            $body = (string) stream_get_contents($stream);
            fclose($stream);
            // Providers rejecting a big output budget → retry at the safe ceiling.
            if (($status === 400 || $status === 413) && !$tokenRetried && (int) ($payload['max_tokens'] ?? 0) > 8192) {
                $payload['max_tokens'] = 8192;
                $tokenRetried = true;
                continue;
            }
            // Transient upstream failures (429 / 5xx) → backoff and retry.
            if ($attempt < 3 && ($status === 429 || $status >= 500)) {
                sleep($attempt === 1 ? 1 : 3);
                continue;
            }
            return null;
        }

        $full = '';
        $sawDone = false;
        $finishReason = null;
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) break;
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'data: ')) {
                $dataBody = substr($trimmed, 6);
                if ($dataBody === '[DONE]') { $sawDone = true; continue; }
                $json = json_decode($dataBody, true);
                if ($json && isset($json['choices'][0])) {
                    $choice = $json['choices'][0];
                    if (isset($choice['finish_reason'])) $finishReason = $choice['finish_reason'];
                    if (isset($choice['delta']['content']) && is_string($choice['delta']['content'])) {
                        $full .= $choice['delta']['content'];
                    }
                }
            }
        }
        fclose($stream);
        return ['content' => $full, 'complete' => $sawDone || $finishReason === 'stop'];
    }
}

/**
 * Stream a completion and auto-continue when the upstream cuts the
 * connection mid-generation — the Neural Host closes SSE at ~120 s, so a
 * long build always truncates on the first request.
 *
 * Each continuation round asks the model to resume exactly where it
 * stopped. Small models often ignore that and re-generate a fresh answer
 * instead, so $isValid decides what counts as a win: a complete round
 * that is already a valid payload (re-generation), else the
 * concatenation (true continuation). Returns the winning text, or null
 * on hard failure.
 *
 * @param callable(string):bool $isValid
 */
function generateComplete(string $endpoint, array $headers, array $payload, callable $isValid, int $maxRounds = 4): ?string
{
    $round = 0;
    $rounds = [];
    do {
        $res = streamCompletion($endpoint, $headers, $payload);
        if ($res === null) return null; // hard failure (auth, 5xx, connection)
        $rounds[] = $res['content'];
        if ($res['complete']) {
            // Fresh re-generation in the latest round wins immediately.
            if ($isValid($res['content'])) return $res['content'];
            // Otherwise fall back to the true-continuation concatenation.
            $full = implode('', $rounds);
            if ($isValid($full)) return $full;
        }
        $full = implode('', $rounds);
        if (strlen($full) > 5 * 1024 * 1024) return $full; // safety cap
        $round++;
        if ($round > $maxRounds) break;

        $tail = mb_substr($full, -2500);
        $payload = [
            'model'       => $payload['model'],
            'messages'    => [
                $payload['messages'][0] ?? ['role' => 'system', 'content' => 'You are ASHAT, an AI coding agent.'],
                [
                    'role'    => 'user',
                    'content' => "The assistant's previous response was cut off mid-generation. "
                        . "Continue it EXACTLY from where it stopped. Do NOT repeat anything already produced.\n\n"
                        . "Where it stopped:\n\n```\n" . $tail . "\n```\n\n"
                        . 'Continue the response now. Output only the continuation.',
                ],
            ],
            'max_tokens'  => 4096,
            'temperature' => 0.3,
            'stream'      => true,
        ];
        out('Neural Host cut the response at ~120 s — continuing generation (round ' . $round . ')…');
    } while (true);

    return $full;
}


/**
 * Pull a doc out of a model response — shared
 * SystemValidationEngine::docFromMarkdown (plain markdown, fence-stripped,
 * with JSON-wrapper recovery).
 */
function docFromJson(string $content, string $key): string
{
    return \Core\SystemValidationEngine::docFromMarkdown($content, $key);
}

/**
 * One local round-trip through the 450M VL (Intent Router). Plain JSON —
 * alpha-server returns one completion per request. The remote hosts
 * (Omega/Beta/Delta) are code agents, so brainstorm + intent summarization
 * always run here. Returns the accumulated text, or null on failure.
 */
function localVL(string $system, string $user, int $maxTokens = 1500): ?string
{
    return \Models\ChatBackend::localVL(
        [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        $maxTokens
    );
}

// ═════════════════════════════════════════════════════════════════════
//  PROMPTS — identical to the web controllers so Omega sees the same
//  contracts it already answers.
// ═════════════════════════════════════════════════════════════════════

const SPEC_PROMPT = <<<'MD'
You are ASHAT, a software architect. Given a project idea, write a concise markdown SPECIFICATION with exactly these sections:

# Project: <title>
## Overview
## Requirements
## Technical Stack
## File Structure
## Acceptance Criteria

Output ONLY the markdown document itself — no JSON wrapper, no code fences, no commentary, no headings about the format.
MD;

const BUILD_PROMPT = <<<'MD'
You are ASHAT, a build planner. Given a specification, write a BUILD PLAN in markdown with exactly these sections:

## Build Order
## Tasks
## Risks
## Verification

Output ONLY the markdown document itself — no JSON wrapper, no code fences, no commentary, no headings about the format.
MD;

/** Build-mode generation messages — mirror of BuildPipelineController::generationMessages(). */
function generationMessages(array $body): array
{
    $spec = trim((string) ($body['spec'] ?? ''));
    $plan = trim((string) ($body['plan'] ?? ''));
    $lang = trim((string) ($body['language'] ?? ''));

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

// ═════════════════════════════════════════════════════════════════════
//  COMMAND: status — print the Build-mode gate state
// ═════════════════════════════════════════════════════════════════════

function cmdStatus(string $outDir): void
{
    $spec  = $outDir . '/Spec.md';
    $build = $outDir . '/Build.md';
    $hasSpec  = is_file($spec);
    $hasBuild = is_file($build);

    echo "Project dir: {$outDir}\n";
    echo "Spec.md:     " . ($hasSpec ? 'present (' . number_format((float) filesize($spec)) . ' bytes)' : 'missing (optional artifact)') . "\n";
    echo "Build.md:    " . ($hasBuild ? 'present (' . number_format((float) filesize($build)) . ' bytes)' : 'missing (optional artifact)') . "\n";
    echo "Gate:        none — build runs from natural language (build \"<idea>\") or Spec.md\n";

    exit(0);
}

// ═════════════════════════════════════════════════════════════════════
//  COMMAND: brainstorm — idea → Spec.md + Build.md (local 450M VL)
// ═════════════════════════════════════════════════════════════════════

function cmdBrainstorm(string $idea, array $opts): void
{
    $routerUrl = rtrim((string) \Core\ConfigBag::getInstance()->intentRouterUrl(), '/');
    if ($routerUrl === '' || !intentRouterUp()) {
        fail('No local Intent Router (450M VL) is up — brainstorming needs it. Set INTENT_ROUTER_URL or start alpha-server.');
    }

    $outDir = (string) ($opts['out'] ?? getcwd() ?: '.');
    if (!is_dir($outDir) && !@mkdir($outDir, 0775, true)) {
        fail("Cannot create output directory: {$outDir}");
    }

    step('Brainstorming (local 450M VL)');
    out('Writing the specification (Spec.md)…');
    $specContent = localVL(SPEC_PROMPT, "Project idea:\n\n" . $idea);
    if ($specContent === null) fail('Local 450M VL did not respond for the spec. Check the Intent Router and try again.');
    $spec = docFromJson($specContent, 'spec');
    if ($spec === '') {
        fail('Local 450M VL did not produce a usable spec. Raw start: ' . mb_substr($specContent, 0, 300));
    }

    out('Writing the build plan (Build.md)…');
    $buildContent = localVL(BUILD_PROMPT, "Specification:\n\n" . mb_substr($spec, 0, 3000));
    if ($buildContent === null) fail('Local 450M VL did not respond for the build plan. Try again.');
    $build = docFromJson($buildContent, 'build');
    if ($build === '') {
        fail('Local 450M VL did not produce a usable build plan. Raw start: ' . mb_substr($buildContent, 0, 300));
    }

    // 1-to-1-to-1 slot: exactly one Spec.md + one Build.md per project.
    file_put_contents($outDir . '/Spec.md', $spec);
    file_put_contents($outDir . '/Build.md', $build);

    step('Brainstorm complete');
    out('Wrote Spec.md  (' . number_format((float) strlen($spec)) . ' bytes)');
    out('Wrote Build.md (' . number_format((float) strlen($build)) . ' bytes)');
    out('Docs are artifacts — build works from natural language too: php bin/ashat-build.php build "<your idea>" --out ' . escapeshellarg($outDir));

    if (!empty($opts['json'])) {
        echo json_encode([
            'ok'    => true,
            'spec'  => $spec,
            'build' => $build,
            'paths' => [$outDir . '/Spec.md', $outDir . '/Build.md'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

// ═════════════════════════════════════════════════════════════════════
//  COMMAND: build — the full pipeline (generate → validate → write)
// ═════════════════════════════════════════════════════════════════════

function cmdBuild(array $opts, string $idea = ''): void
{
    $brainstem = resolveBrainstem($opts);
    if ($brainstem['api_key'] === '') {
        fail('No BrainStem host is configured for code generation. Set BRAINSTEM_URL/BRAINSTEM_KEY or pass --url/--key.');
    }

    $outDir = (string) ($opts['out'] ?? getcwd() ?: '.');
    if (!is_dir($outDir)) {
        fail("Project directory does not exist: {$outDir} (create it first)");
    }

    // No gate: build runs from a natural-language idea (summarized by the
    // local 450M VL) or from an existing Spec.md. Build.md is optional.
    $idea = trim($idea);
    if ($idea === '' && isset($opts['idea'])) $idea = trim((string) $opts['idea']);
    if ($idea !== '' && isset($opts['spec'])) {
        out('note: --spec is ignored when an idea is given.');
    }
    $spec = '';
    $plan = '';
    if ($idea !== '') {
        step('Intent summary (local 450M VL)');
        out('Summarizing your request into a spec…');
        $specContent = localVL(SPEC_PROMPT, "Project idea:\n\n" . $idea);
        if ($specContent === null) {
            fail('Local 450M VL did not respond. Check the Intent Router (INTENT_ROUTER_URL) and try again.');
        }
        $spec = docFromJson($specContent, 'spec');
        if ($spec === '') {
            fail('Local 450M VL could not turn your request into a spec. Try rephrasing, or write Spec.md yourself and run build without an idea.');
        }
        // Artifact only — never a gate.
        file_put_contents($outDir . '/Spec.md', $spec);
        out('Spec.md artifact written (' . number_format((float) strlen($spec)) . ' bytes).');
        $planPath = (string) ($opts['plan'] ?? $outDir . '/Build.md');
        if (is_file($planPath)) $plan = (string) file_get_contents($planPath);
    } else {
        $specPath = (string) ($opts['spec'] ?? $outDir . '/Spec.md');
        if (!is_file($specPath)) {
            fail('Nothing to build: give a natural-language idea (build "<your idea>") or point at a spec (--spec FILE or ' . escapeshellarg($outDir . '/Spec.md') . ').');
        }
        $spec = (string) file_get_contents($specPath);
        $planPath = (string) ($opts['plan'] ?? $outDir . '/Build.md');
        if (is_file($planPath)) $plan = (string) file_get_contents($planPath);
    }
    if (trim($spec) === '') fail('The spec is empty — give an idea or fix the file.');

    // BrainStem requires the exact GGUF model id; probe when not configured.
    if ($brainstem['model'] === '') {
        out('Probing BrainStem models endpoint…');
        $brainstem['model'] = probeBrainstemModel($brainstem['url']);
        if ($brainstem['model'] === '') {
            fail('BrainStem did not report a model — pass --model <GGUF id> explicitly.');
        }
    }

    $routerUrl = \Core\ConfigBag::getInstance()->intentRouterUrl();
    $hasRouter = trim($routerUrl) !== '' && intentRouterUp();

    $models = [];
    if ($hasRouter) {
        $models[] = \Models\ChatBackend::defaultIntentRouterLabel();
        $models[] = \Models\ChatBackend::defaultVisionLabel();
    }
    $models[] = $brainstem['model'] !== '' ? $brainstem['model'] : \Models\ChatBackend::defaultBrainstemLabel();

    step('Build pipeline (' . implode(' → ', array_unique($models)) . ')');

    // ── Phase 1: BrainStem generates the code ──────────────────────
    // Streamed (not the web's plain POST): the non-streaming path times
    // out at 120 s on long generations; token deltas keep this alive.
    out('Generating project files…');
    $backend = \Models\ChatBackend::select($brainstem, null, '');
    if (!$backend->isAvailable()) {
        fail('BrainStem host is not configured.');
    }
    $messages = generationMessages([
        'spec'     => $spec,
        'plan'     => $plan,
        'language' => (string) ($opts['language'] ?? ''),
    ]);
    $req  = $backend->buildRequest($messages, ['max_tokens' => 16384, 'temperature' => 0.6], true);
    $isValid = static function (string $text): bool {
        $p = \Core\SystemValidationEngine::lenientJson($text);
        return is_array($p) && !empty($p['files']) && is_array($p['files']);
    };
    $content = generateComplete($req['endpoint'], $req['headers'], $req['payload'], $isValid);
    if ($content === null || trim($content) === '') {
        fail('BrainStem did not respond. Check the Neural Host and try again.');
    }

    $parsed = \Core\SystemValidationEngine::lenientJson($content);
    if (!is_array($parsed) || empty($parsed['files']) || !is_array($parsed['files'])) {
        fail('BrainStem did not return a valid build payload (expected {plan, files[]}). The Neural Host keeps cutting long generations at ~120 s — retry now that the model is warm, or split the spec into smaller builds.');
    }

    // Same defense-in-depth caps as the web pipeline.
    $MAX_FILE_BYTES = 250 * 1024;
    $MAX_TOTAL_BYTES = 5 * 1024 * 1024;

    $files = [];
    foreach ($parsed['files'] as $f) {
        if (!is_array($f) || !isset($f['path']) || !isset($f['content'])) continue;
        $path = sanitizePath((string) $f['path']);
        if ($path === '') continue;
        $contentStr = (string) $f['content'];
        if (strlen($contentStr) > $MAX_FILE_BYTES) continue;
        $files[] = [
            'path'     => $path,
            'content'  => $contentStr,
            'language' => \Core\LanguageDetector::detect($path),
        ];
    }
    if (empty($files)) {
        fail('BrainStem returned no usable files.');
    }
    $totalBytes = array_sum(array_map('strlen', array_column($files, 'content')));
    if ($totalBytes > $MAX_TOTAL_BYTES) {
        fail('Generated project exceeds the 5 MB build cap.');
    }
    $planText = trim((string) ($parsed['plan'] ?? '')) ?: 'Built ' . count($files) . ' file(s) from your spec.';

    // ── Phase 2: 350M debug pass per file ───────────────────────────
    $validated = [];
    $frontEnd  = [];
    $allIssues = [];
    if ($hasRouter && empty($opts['no-debug'])) {
        step('Validating project (local 350M debug pass)');
        foreach ($files as $f) {
            out('Validating ' . $f['path'] . '…');
            $static = \Core\SystemValidationEngine::staticCheck($f['path'], $f['content']);
            $dbg = \Core\SystemValidationEngine::debugFile(
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
        if (!$hasRouter) out('Local Intent Router is down — skipping the 350M debug pass.');
        if (!empty($opts['no-debug'])) out('--no-debug: skipping the 350M debug pass.');
        foreach ($files as $f) {
            $f['issues'] = [];
            $f['valid']  = true;
            $validated[] = $f;
            if (preg_match('/\.(html?|css|js|mjs)$/i', $f['path']) === 1) $frontEnd[] = $f;
        }
    }

    // ── Phase 3: VL visual check (front-end files) ─────────────────
    if ($hasRouter && !empty($frontEnd) && empty($opts['no-visual'])) {
        step('Visual check (headless Chromium → VL review)');
        out('Rendering ' . count($frontEnd) . ' front-end file(s)…');
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
        usleep(500_000); // 0.5 s pause for swap, like the web pipeline
        $workDir = sys_get_temp_dir() . '/ashat-visual-' . bin2hex(random_bytes(4));
        @mkdir($workDir, 0775, true);
        $vis = \Core\SystemValidationEngine::visualCheck($frontEnd, $workDir, (string) $routerUrl);
        ashat_cli_clean_dir($workDir);
        if (!$vis['ok'] && trim((string) $vis['findings']) !== '') {
            $allIssues[] = 'Visual: ' . trim($vis['findings']);
        }
        out($vis['ok'] ? 'Visual check passed.' : 'Visual check noted issues (advisory).');
    } elseif (!empty($frontEnd) && empty($opts['no-visual'])) {
        out('Skipping visual check (no Intent Router).');
    }

    // ── Phase 4: write validated files into the project dir ────────
    step('Writing files into ' . $outDir);
    $saved = 0;
    $savedPaths = [];
    foreach ($validated as $f) {
        $dest = $outDir . '/' . $f['path'];
        $dir  = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $allIssues[] = $f['path'] . ': could not create directory';
            continue;
        }
        if (@file_put_contents($dest, $f['content']) === false) {
            $allIssues[] = $f['path'] . ': could not save';
            continue;
        }
        $saved++;
        $savedPaths[] = ['path' => $f['path'], 'language' => $f['language']];
        out('  ✓ ' . $f['path']);
    }

    step('Build complete');
    out($planText);
    out($saved . ' file(s) written (' . number_format((float) $totalBytes) . ' bytes total).');
    if (!empty($allIssues)) {
        out(count($allIssues) . ' validation note(s) — first 40:');
        foreach (array_slice($allIssues, 0, 40) as $issue) out('  • ' . $issue);
    }

    if (!empty($opts['json'])) {
        echo json_encode([
            'ok'     => true,
            'plan'   => $planText,
            'files'  => $savedPaths,
            'saved'  => $saved,
            'issues' => array_slice($allIssues, 0, 40),
            'models' => $models,
            'out'    => $outDir,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

/** Recursively remove a scratch directory — mirror of the controller's cleanDir(). */
function ashat_cli_clean_dir(string $dir): void
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

// ═════════════════════════════════════════════════════════════════════
//  ARG PARSING + DISPATCH
// ═════════════════════════════════════════════════════════════════════

/** Options that require a value (reject a bare --flag). */
const VALUE_OPTS = ['out', 'spec', 'plan', 'language', 'url', 'key', 'model', 'idea'];

/** Parse argv after the script name: --opt val, --opt=val, --flag, positionals. */
function parseArgs(array $argv): array
{
    $cmd = null;
    $opts = [];
    $positionals = [];
    for ($i = 0; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($cmd === null && !str_starts_with($arg, '-')) {
            $cmd = $arg;
            continue;
        }
        if (str_starts_with($arg, '--')) {
            $arg = substr($arg, 2);
            $eq = strpos($arg, '=');
            if ($eq !== false) {
                $opts[substr($arg, 0, $eq)] = substr($arg, $eq + 1);
            } else {
                $next = $argv[$i + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '-')) {
                    $opts[$arg] = $next;
                    $i++;
                } elseif (in_array($arg, VALUE_OPTS, true)) {
                    fail("Option --{$arg} requires a value.");
                } else {
                    $opts[$arg] = true;
                }
            }
            continue;
        }
        if ($arg === '-h') { $opts['help'] = true; continue; }
        $positionals[] = $arg;
    }
    return [$cmd, $opts, $positionals];
}

function printUsage(): void
{
    echo <<<USAGE
ASHAT Hub — Chat Studio Build mode CLI

Rebuilds the browser-only Build tab as a terminal tool. The local 450M VL
(intent router) handles brainstorming + intent summarization; the remote
hosts (Omega/Beta/Delta) are code agents only. No Spec.md/Build.md gate —
build works from natural language. Pipeline: intent summary (450M VL) →
code generation (Omega) → static gates + 350M debug pass → VL visual
check → write files.

Usage:
  php bin/ashat-build.php brainstorm <idea…> [--out DIR] [--json]
  php bin/ashat-build.php build <idea…> [--out DIR] [--spec FILE] [--plan FILE]
                                [--language LANG] [--no-debug] [--no-visual]
                                [--json] [--url U] [--key K] [--model M]
  php bin/ashat-build.php status [--out DIR]
  php bin/ashat-build.php help

Commands:
  brainstorm   Turn a project idea into Spec.md + Build.md via the LOCAL
               450M VL (intent router). Both docs are artifacts, not gates.
  build        Build from a natural-language idea (summarized into a spec
               by the local 450M VL) or from an existing Spec.md, then run
               the validation pipeline and write the files. NO gate.
  status       Show project-doc state (informational — always exit 0).

Options:
  --out DIR      Project directory (default: current working directory)
  --spec FILE    Spec path override (default: DIR/Spec.md)
  --plan FILE    Build plan path override (default: DIR/Build.md)
  --language LANG  Force a language/stack hint for the generator
  --url U        BrainStem Neural Host URL override
  --key K        BrainStem API key override (prefer BRAINSTEM_KEY in
                 server_config.json/.env — a flag is visible in ps/Shell history)
  --no-debug     Skip the 350M debug pass (also auto-skipped when the
                 local Intent Router at INTENT_ROUTER_URL is unreachable)
  --no-visual    Skip the headless-render VL visual check
  --json         Print a machine-readable JSON result to stdout (progress
                 always goes to stderr)

Config: BRAINSTEM_URL / BRAINSTEM_KEY / INTENT_ROUTER_URL resolve from
config/server_config.json → .env → defaults, exactly like the web app.

Examples:
  php bin/ashat-build.php brainstorm "A tiny static landing page with a hero and contact form" --out ./myapp
  php bin/ashat-build.php build "make me a todo app" --out ./myapp --json
  php bin/ashat-build.php build --out ./myapp --json
  php bin/ashat-build.php status --out ./myapp

USAGE;
}

[$cmd, $opts, $positionals] = parseArgs(array_slice($argv, 1));

if (!empty($opts['help']) || $cmd === 'help' || $cmd === null) {
    printUsage();
    exit(0);
}

switch ($cmd) {
    case 'brainstorm': {
        $idea = trim(implode(' ', $positionals));
        if (isset($opts['idea']) && $idea === '') $idea = trim((string) $opts['idea']);
        if ($idea === '') fail('brainstorm needs an idea: php bin/ashat-build.php brainstorm "<your idea>"');
        cmdBrainstorm($idea, $opts);
        break;
    }
    case 'build':
        cmdBuild($opts, trim(implode(' ', $positionals)));
        break;
    case 'status':
        cmdStatus((string) ($opts['out'] ?? getcwd() ?: '.'));
        break;
    default:
        fail("Unknown command '{$cmd}' — run php bin/ashat-build.php help");
}
