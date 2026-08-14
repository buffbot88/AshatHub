<?php
declare(strict_types=1);
namespace Controllers;

use Core\ConfigBag;
use Core\RequestContext;
use Core\SseStreamer;
use Core\Http;
use Models\ChatBackend;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\GalileoChatController — Galileo Studio chat API.
 *
 * Architecture: 450M VL is the architect, 1.2B is the builder.
 *
 * Flow for coding requests:
 *   1. 450M VL classifies intent (classifyIntent)
 *   2. 450M VL creates a build plan — file list, architecture, specs
 *      (createBuildPlan)
 *   3. 1.2B Instruct receives the plan and generates code files
 *      (routeToCodingAgent)
 *
 * The 450M does the thinking. The 1.2B does the writing.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GalileoChatController
{
    // ── Token budgets ─────────────────────────────────────────────
    private const PROJECT_CONTEXT_CHARS  = 2000;
    private const ACTIVE_FILE_CHARS      = 3000;
    private const CODING_PROJECT_CHARS   = 2000;
    private const CODING_FILE_CHARS      = 3000;
    private const LOCAL_CHAT_CHARS       = 1500;

    public function chat(RequestContext $ctx): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(900);
        }
        SseStreamer::headers();
        try {
            $this->handleChat($ctx);
        } catch (\Throwable $e) {
            try {
                SseStreamer::send('error', ['message' => 'Chat failed: ' . $e->getMessage()]);
            } catch (\Throwable $ignored) {
            }
        }
    }

    private function handleChat(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $message = trim((string) ($body['message'] ?? ''));
        $projectId = trim((string) ($body['project_id'] ?? ''));
        $conversationId = trim((string) ($body['conversation_id'] ?? ''));
        $activeFile = trim((string) ($body['active_file'] ?? ''));

        if ($message === '') {
            SseStreamer::send('error', ['message' => 'message_required']);
            return;
        }

        $userId = (string) $ctx->user()['id'];

        // Phase 1: 450M classifies intent
        $intent = $this->classifyIntent($userId, $message, $projectId);

        switch ($intent) {
            case 'coding_request':
                // Phase 2: 450M creates build plan
                // Phase 3: 1.2B generates code from plan
                $this->buildAndCode($ctx, $userId, $message, $projectId, $activeFile);
                break;

            case 'brainstorm':
                // 450M brainstorms with the user
                $this->routeToLocalChat($ctx, $userId, $message, $projectId, $activeFile, 'brainstorm');
                break;

            case 'conversation':
            case 'project_question':
            default:
                $this->routeToLocalChat($ctx, $userId, $message, $projectId, $activeFile);
                break;
        }
    }

    // ── Phase 1: Intent Classification ────────────────────────────

    private function classifyIntent(string $userId, string $message, string $projectId): string
    {
        $routerUrl = trim((string) ConfigBag::getInstance()->intentRouterUrl());
        if ($routerUrl === '') {
            return 'coding_request';
        }

        $systemPrompt = "Classify this message into ONE category: conversation, project_question, brainstorm, coding_request, debug, review, refactor, preview_issue, file_operation. Reply with ONLY the category name.";

        $content = ChatBackend::localVL(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $message],
            ],
            30
        );

        if ($content === null) {
            return 'coding_request';
        }

        $intent = strtolower(trim($content));
        $validIntents = ['conversation', 'project_question', 'brainstorm', 'coding_request',
                         'debug', 'review', 'refactor', 'preview_issue', 'file_operation'];

        return in_array($intent, $validIntents, true) ? $intent : 'coding_request';
    }

    // ── Phase 2+3: Build Plan + Code Generation ──────────────────

    /**
     * The 450M VL creates a build plan, then the 1.2B generates code.
     *
     * This is the key architectural improvement:
     * - 450M = architect (understands, plans, structures)
     * - 1.2B = builder (writes code from the plan)
     */
    private function buildAndCode(
        RequestContext $ctx,
        string $userId,
        string $message,
        string $projectId,
        string $activeFile
    ): void {
        $routerUrl = trim((string) ConfigBag::getInstance()->intentRouterUrl());
        $projectContext = $this->buildProjectContext($userId, $projectId, self::CODING_PROJECT_CHARS);

        // ── Phase 2: 450M VL creates the build plan ──────────────
        SseStreamer::send('progress', ['message' => 'Analyzing request and creating build plan...']);

        $plan = $this->createBuildPlan($routerUrl, $message, $projectContext);

        if ($plan === null) {
            // Fallback: skip planning, send raw to coding agent
            SseStreamer::send('progress', ['message' => 'Planning skipped — sending directly to agent...']);
            $this->routeToCodingAgent($ctx, $userId, $message, $projectContext, $activeFile);
            return;
        }

        // Show the plan to the user
        SseStreamer::send('progress', ['message' => 'Build plan created:']);
        SseStreamer::send('progress', ['message' => $plan['summary']]);

        if (!empty($plan['files'])) {
            $fileList = implode(', ', array_column($plan['files'], 'path'));
            SseStreamer::send('progress', ['message' => 'Files to create: ' . $fileList]);
        }

        // ── Phase 3: 1.2B generates code from the plan ───────────
        SseStreamer::send('progress', ['message' => 'Submitting plan to coding agent...']);

        $this->routeToCodingAgentWithPlan($ctx, $userId, $plan, $projectContext, $activeFile);
    }

    /**
     * 450M VL creates a structured build plan.
     *
     * Returns: { "summary": "...", "files": [{ "path": "...", "purpose": "..." }], "architecture": "..." }
     * Or null on failure.
     */
    private function createBuildPlan(string $routerUrl, string $message, string $projectContext): ?array
    {
        // Look up relevant skills before planning
        $skills = $this->lookupSkills($message);
        $skillsContext = '';
        if ($skills !== '') {
            $skillsContext = "\n\nRelevant skills and best practices:\n" . $skills;
        }

        $systemPrompt = "You are a software architect. Given a user's request, create a build plan.\n"
            . "Return a JSON object with this exact structure:\n"
            . "{\"summary\": \"1-2 sentence summary of what to build\", "
            . "\"architecture\": \"brief architecture description\", "
            . "\"files\": [{\"path\": \"relative/file/path\", \"purpose\": \"what this file does\"}]}\n"
            . "Rules:\n"
            . "- List every file that needs to be created or modified\n"
            . "- Use relative paths (no leading slash)\n"
            . "- Be specific about each file's purpose\n"
            . "- Include config files, entry points, and core modules\n"
            . "- Follow the provided best practices and patterns\n"
            . "- Output ONLY the JSON object, no prose";

        $userPrompt = $message . $skillsContext;
        if ($projectContext !== '') {
            $userPrompt = "Existing project:\n" . mb_substr($projectContext, 0, 1000) . "\n\n" . $userPrompt;
        }

        $content = ChatBackend::localVL(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            512  // enough for a plan, not full code
        );

        if ($content === null || trim($content) === '') {
            return null;
        }

        $parsed = Http::lenientJson($content);
        if (!is_array($parsed) || empty($parsed['summary'])) {
            return null;
        }

        return $parsed;
    }

    /**
     * Send the structured plan to the 1.2B coding agent.
     */
    private function routeToCodingAgentWithPlan(
        RequestContext $ctx,
        string $userId,
        array $plan,
        string $projectContext,
        string $activeFile
    ): void {
        $brainstem = RepositoryRegistry::brainstemConfig()->active();
        if (empty($brainstem['api_key'])) {
            SseStreamer::send('error', ['message' => 'No coding agent configured.']);
            return;
        }

        $backend = ChatBackend::select($brainstem, null, '');
        if (!$backend->isAvailable()) {
            SseStreamer::send('error', ['message' => 'Coding agent is not available.']);
            return;
        }

        // Build a detailed prompt from the plan
        $planSummary = $plan['summary'] ?? '';
        $planArchitecture = $plan['architecture'] ?? '';
        $planFiles = $plan['files'] ?? [];

        $fileSpecs = '';
        foreach ($planFiles as $f) {
            $fileSpecs .= "- {$f['path']}: {$f['purpose']}\n";
        }

        $userPrompt = "Build plan:\n"
            . "Summary: {$planSummary}\n"
            . "Architecture: {$planArchitecture}\n\n"
            . "Files to create:\n{$fileSpecs}\n"
            . "Generate ALL listed files with complete, working, production-ready code.";

        if ($projectContext !== '') {
            $userPrompt = "Existing project files:\n" . $projectContext . "\n\n" . $userPrompt;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->codingSystemPrompt()],
            ['role' => 'user',   'content' => $userPrompt],
        ];

        $req = $backend->buildRequest($messages, [
            'max_tokens' => 16384,
            'temperature' => 0.6,
        ], false);

        $inputTokens = $this->estimateTokens($messages);
        SseStreamer::send('progress', ['message' => "Generating code... (~{$inputTokens} input tokens)"]);

        $resp = Http::postJson($req['endpoint'], $req['headers'], $req['payload']);
        if ($resp === null) {
            SseStreamer::send('error', ['message' => 'The coding agent did not respond.']);
            return;
        }

        $content = $resp['choices'][0]['message']['content'] ?? '';
        $usage = $resp['usage'] ?? null;
        $outputTokens = $usage['completion_tokens'] ?? 0;
        $totalTokens = $usage['total_tokens'] ?? ($inputTokens + $outputTokens);

        if ($content === '') {
            SseStreamer::send('error', ['message' => 'The coding agent returned an empty response.']);
            return;
        }

        if ($totalTokens > 0) {
            SseStreamer::send('progress', ['message' => "Tokens: {$totalTokens} (in: {$inputTokens}, out: {$outputTokens})"]);
        }

        $parsed = Http::lenientJson($content);

        if (is_array($parsed) && !empty($parsed['files']) && is_array($parsed['files'])) {
            $this->handleCodingResult($ctx, $userId, $parsed, $planSummary);
        } else {
            SseStreamer::send('done', [
                'type'    => 'conversation',
                'content' => $content,
                'tokens'  => $totalTokens > 0 ? $totalTokens : null,
            ]);
        }
    }

    /**
     * Fallback: send raw request to coding agent (no plan).
     */
    private function routeToCodingAgent(
        RequestContext $ctx,
        string $userId,
        string $message,
        string $projectContext,
        string $activeFile
    ): void {
        $brainstem = RepositoryRegistry::brainstemConfig()->active();
        if (empty($brainstem['api_key'])) {
            SseStreamer::send('error', ['message' => 'No coding agent configured.']);
            return;
        }

        $backend = ChatBackend::select($brainstem, null, '');
        if (!$backend->isAvailable()) {
            SseStreamer::send('error', ['message' => 'Coding agent is not available.']);
            return;
        }

        $fileContext = $activeFile !== ''
            ? $this->getFileContent($userId, $activeFile, self::CODING_FILE_CHARS)
            : '';

        $userPrompt = $message;
        if ($projectContext !== '') {
            $userPrompt = "Existing project files:\n" . $projectContext . "\n\n" . $userPrompt;
        }
        if ($fileContext !== '') {
            $userPrompt = "Active file:\n" . $fileContext . "\n\n" . $userPrompt;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->codingSystemPrompt()],
            ['role' => 'user',   'content' => $userPrompt],
        ];

        $req = $backend->buildRequest($messages, [
            'max_tokens' => 16384,
            'temperature' => 0.6,
        ], false);

        $inputTokens = $this->estimateTokens($messages);
        SseStreamer::send('progress', ['message' => "Generating code... (~{$inputTokens} input tokens)"]);

        $resp = Http::postJson($req['endpoint'], $req['headers'], $req['payload']);
        if ($resp === null) {
            SseStreamer::send('error', ['message' => 'The coding agent did not respond.']);
            return;
        }

        $content = $resp['choices'][0]['message']['content'] ?? '';
        $usage = $resp['usage'] ?? null;
        $outputTokens = $usage['completion_tokens'] ?? 0;
        $totalTokens = $usage['total_tokens'] ?? ($inputTokens + $outputTokens);

        if ($content === '') {
            SseStreamer::send('error', ['message' => 'The coding agent returned an empty response.']);
            return;
        }

        $parsed = Http::lenientJson($content);

        if (is_array($parsed) && !empty($parsed['files']) && is_array($parsed['files'])) {
            $this->handleCodingResult($ctx, $userId, $parsed, '');
        } else {
            SseStreamer::send('done', [
                'type'    => 'conversation',
                'content' => $content,
                'tokens'  => $totalTokens > 0 ? $totalTokens : null,
            ]);
        }
    }

    // ── Local Chat ───────────────────────────────────────────────

    private function routeToLocalChat(
        RequestContext $ctx,
        string $userId,
        string $message,
        string $projectId,
        string $activeFile,
        string $mode = 'conversation'
    ): void {
        $routerUrl = trim((string) ConfigBag::getInstance()->intentRouterUrl());
        if ($routerUrl === '') {
            SseStreamer::send('error', ['message' => 'The local AI is not available.']);
            return;
        }

        $projectContext = $this->buildProjectContext($userId, $projectId, self::LOCAL_CHAT_CHARS);
        $fileContext = $activeFile !== ''
            ? $this->getFileContent($userId, $activeFile, self::ACTIVE_FILE_CHARS)
            : '';

        $systemPrompt = "You are Galileo, Ashat Hub's AI coding assistant. Be helpful, concise, and technically accurate.";
        if ($mode === 'brainstorm') {
            $systemPrompt = "You are Galileo, a software architect. Help the user brainstorm and plan their project. "
                . "Ask clarifying questions, suggest features, discuss architecture. Be creative but practical.";
        }

        $userPrompt = $message;
        if ($projectContext !== '') {
            $userPrompt = "Project:\n" . $projectContext . "\n\n" . $userPrompt;
        }
        if ($fileContext !== '') {
            $userPrompt = "File:\n" . $fileContext . "\n\n" . $userPrompt;
        }

        $content = ChatBackend::localVL(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            2048
        );

        if ($content === null) {
            SseStreamer::send('error', ['message' => 'The local AI did not respond. Try again.']);
            return;
        }

        SseStreamer::send('done', [
            'type'    => 'conversation',
            'content' => $content,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function codingSystemPrompt(): string
    {
        return "You are ASHAT, an AI coding agent. You receive a structured build plan and generate code.\n"
            . "Return a JSON object: {\"plan\": \"summary\", \"files\": [{\"path\": \"...\", \"content\": \"...\", \"language\": \"...\"}]}\n"
            . "Rules: generate ALL files from the plan, production-ready code, relative paths, complete files, no secrets, JSON only.";
    }

    private function handleCodingResult(RequestContext $ctx, string $userId, array $parsed, string $planSummary): void
    {
        $plan = $planSummary ?: trim((string) ($parsed['plan'] ?? ''));
        if ($plan !== '') {
            SseStreamer::send('progress', ['message' => 'Plan: ' . mb_substr($plan, 0, 200)]);
        }

        $files = [];
        foreach ($parsed['files'] as $f) {
            if (!is_array($f) || !isset($f['path']) || !isset($f['content'])) continue;
            $path = $this->sanitizePath((string) $f['path']);
            if ($path === '') continue;
            $contentStr = (string) $f['content'];
            if (strlen($contentStr) > 250 * 1024) continue;
            $files[] = [
                'path'     => $path,
                'content'  => $contentStr,
                'language' => \Core\LanguageDetector::detect($path),
            ];
        }

        if (empty($files)) {
            SseStreamer::send('done', [
                'type'    => 'conversation',
                'content' => $plan !== '' ? $plan : 'The agent produced a response but no files were generated.',
            ]);
            return;
        }

        SseStreamer::send('progress', ['message' => 'Writing ' . count($files) . ' file(s)...']);

        $repo = RepositoryRegistry::file();
        $saved = 0;
        $savedPaths = [];
        $issues = [];

        foreach ($files as $f) {
            try {
                $repo->save($userId, $f['path'], $f['content'], $f['language'], true);
                $saved++;
                $savedPaths[] = ['path' => $f['path'], 'language' => $f['language']];
                SseStreamer::send('progress', ['message' => '✓ ' . $f['path']]);
            } catch (\Throwable $e) {
                $issues[] = $f['path'] . ': ' . $e->getMessage();
            }
        }

        SseStreamer::send('done', [
            'type'         => 'coding_result',
            'files'        => $savedPaths,
            'saved'        => $saved,
            'plan'         => $plan,
            'issues'       => array_slice($issues, 0, 20),
        ]);
    }

    private function buildProjectContext(string $userId, string $projectId, int $budgetChars = 2000): string
    {
        try {
            $repo = RepositoryRegistry::file();
            $rows = $repo->allWithContent($userId);
            $context = '';
            $fileCount = 0;

            foreach ($rows as $f) {
                $path = $f['path'];
                $content = (string) ($f['content'] ?? '');
                if ($content === '') continue;

                $entry = "--- $path ---\n" . mb_substr($content, 0, 400) . "\n\n";
                if (strlen($context) + strlen($entry) > $budgetChars) break;
                $context .= $entry;
                $fileCount++;
            }

            if ($fileCount > 0) {
                $context = "({$fileCount} files)\n" . $context;
            }

            return $context;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getFileContent(string $userId, string $filePath, int $maxChars = 3000): string
    {
        try {
            $repo = RepositoryRegistry::file();
            $file = $repo->findByPath($userId, $filePath);
            return mb_substr((string) ($file['content'] ?? ''), 0, $maxChars);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Look up relevant skills from the skills database based on the user's message.
     * Returns a concatenated string of relevant skill content, or empty string.
     */
    private function lookupSkills(string $message): string
    {
        try {
            $pdo = \Core\Database::connection();
            // Search for skills matching keywords in the message
            $like = '%' . mb_substr($message, 0, 50) . '%';
            $stmt = $pdo->prepare(
                'SELECT name, content, tokens_estimated FROM agent_skills '
                . 'WHERE name LIKE ? OR content LIKE ? '
                . 'ORDER BY tokens_estimated ASC LIMIT 5'
            );
            $stmt->execute([$like, $like]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $result = '';
            $budget = 800; // max chars for skills context
            foreach ($rows as $row) {
                $entry = "[{$row['name']}]\n{$row['content']}\n\n";
                if (strlen($result) + strlen($entry) > $budget) break;
                $result .= $entry;
            }

            return $result;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function estimateTokens(array $messages): int
    {
        $totalChars = 0;
        foreach ($messages as $m) {
            $totalChars += strlen($m['content'] ?? '');
        }
        return (int) ceil($totalChars / 4);
    }

    private function sanitizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('/^\.?\//', '', $path);
        $path = preg_replace('/(?:^|\/)\.\.($|\/)/', '/', $path);
        $path = preg_replace('/{2,}/', '/', $path);
        $path = preg_replace('/[\x00-\x1f]/', '', $path);
        return trim($path, '/');
    }
}
