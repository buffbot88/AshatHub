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
 * Controllers\GalileoChatController — Galileo Studio chat API.
 *
 * Token-optimized: all context budgets are tightly capped to prevent
 * runaway token consumption on the Omega/Beta/Delta servers.
 *
 * Token budget per request:
 *   - Intent classification:  ~200 tokens (user message only)
 *   - Local chat:             ~800 tokens context + 2000 max_tokens
 *   - Coding agent:           ~1000 tokens context + 16384 max_tokens
 *   - File content:           max 3000 chars (~800 tokens)
 *   - Project context:        max 2000 chars (~600 tokens)
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GalileoChatController
{
    // ── Token budgets (chars ≈ tokens * 3.5) ──────────────────────
    private const INTENT_CONTEXT_CHARS   = 0;    // classifier gets NO project context
    private const PROJECT_CONTEXT_CHARS  = 2000; // project files summary
    private const ACTIVE_FILE_CHARS      = 3000; // active file content
    private const CODING_PROJECT_CHARS   = 2000; // project context for coding agent
    private const CODING_FILE_CHARS      = 3000; // active file for coding agent
    private const LOCAL_CHAT_CHARS       = 1500; // project context for local chat

    /**
     * POST /api/galileo/chat — SSE. Main chat endpoint.
     */
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

    /**
     * Core chat handler — classifies intent and routes.
     */
    private function handleChat(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $message = trim((string) ($body['message'] ?? ''));
        $projectId = trim((string) ($body['project_id'] ?? ''));
        $conversationId = trim((string) ($body['conversation_id'] ?? ''));
        $activeFile = trim((string) ($body['active_file'] ?? ''));
        $selection = $body['selection'] ?? null;

        if ($message === '') {
            SseStreamer::send('error', ['message' => 'message_required']);
            return;
        }

        $userId = (string) $ctx->user()['id'];

        // ── Phase 1: Classify intent via local 450M VL ────────────
        // Token budget: ~200 tokens (message only, no project context)
        $intent = $this->classifyIntent($userId, $message, $projectId);

        // ── Phase 2: Route based on classified intent ──────────────
        switch ($intent) {
            case 'coding_request':
                $this->routeToCodingAgent($ctx, $userId, $message, $projectId, $activeFile);
                break;

            case 'conversation':
            case 'project_question':
            case 'brainstorm':
            default:
                $this->routeToLocalChat($ctx, $userId, $message, $projectId, $activeFile);
                break;
        }
    }

    /**
     * Classify the user's intent using the local 450M VL.
     *
     * TOKEN OPTIMIZATION: Sends ONLY the user message — no project context.
     * The classifier doesn't need to know the project to decide intent.
     * This saves ~400 tokens per request.
     */
    private function classifyIntent(string $userId, string $message, string $projectId): string
    {
        $routerUrl = trim((string) ConfigBag::getInstance()->intentRouterUrl());
        if ($routerUrl === '') {
            return 'coding_request';
        }

        // Minimal system prompt — 80 tokens vs previous ~200
        $systemPrompt = "Classify this message into ONE category: conversation, project_question, brainstorm, coding_request, debug, review, refactor, preview_issue, file_operation. Reply with ONLY the category name.";

        // Send ONLY the message — no project context (saves ~400 tokens)
        $content = ChatBackend::localVL(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $message],
            ],
            30  // max_tokens: 30 is enough for a single word
        );

        if ($content === null) {
            return 'coding_request';
        }

        $intent = strtolower(trim($content));
        $validIntents = ['conversation', 'project_question', 'brainstorm', 'coding_request',
                         'debug', 'review', 'refactor', 'preview_issue', 'file_operation'];

        return in_array($intent, $validIntents, true) ? $intent : 'coding_request';
    }

    /**
     * Route a coding request to the Omega/Beta/Delta agent ecosystem.
     */
    private function routeToCodingAgent(
        RequestContext $ctx,
        string $userId,
        string $message,
        string $projectId,
        string $activeFile
    ): void {
        SseStreamer::send('progress', ['message' => 'Analyzing your request...']);

        // TOKEN OPTIMIZATION: Tight budgets for context
        $projectContext = $this->buildProjectContext($userId, $projectId, self::CODING_PROJECT_CHARS);
        $fileContext = $activeFile !== ''
            ? $this->getFileContent($userId, $activeFile, self::CODING_FILE_CHARS)
            : '';

        $normalized = $message;

        SseStreamer::send('progress', ['message' => 'Submitting to coding agent...']);

        $brainstem = RepositoryRegistry::brainstemConfig()->active();
        if (empty($brainstem['api_key'])) {
            SseStreamer::send('error', [
                'message' => 'No coding agent is configured. The BrainStem host is not available.',
            ]);
            return;
        }

        $backend = ChatBackend::select($brainstem, null, '');
        if (!$backend->isAvailable()) {
            SseStreamer::send('error', ['message' => 'Coding agent is not available.']);
            return;
        }

        $messages = $this->buildCodingMessages($normalized, $projectContext, $fileContext);
        $req = $backend->buildRequest($messages, [
            'max_tokens' => 16384,
            'temperature' => 0.6,
        ], false);

        // Estimate and report token usage
        $inputTokens = $this->estimateTokens($messages);
        SseStreamer::send('progress', ['message' => "Agent is working... (~{$inputTokens} input tokens)"]);

        $resp = SystemValidationEngine::postJson($req['endpoint'], $req['headers'], $req['payload']);
        if ($resp === null) {
            SseStreamer::send('error', ['message' => 'The coding agent did not respond. It may be temporarily unavailable.']);
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

        // Report token usage
        if ($totalTokens > 0) {
            SseStreamer::send('progress', ['message' => "Tokens used: {$totalTokens} (in: {$inputTokens}, out: {$outputTokens})"]);
        }

        $parsed = SystemValidationEngine::lenientJson($content);

        if (is_array($parsed) && !empty($parsed['files']) && is_array($parsed['files'])) {
            $this->handleCodingResult($ctx, $userId, $parsed, $projectId);
        } else {
            SseStreamer::send('done', [
                'type'    => 'conversation',
                'content' => $content,
                'tokens'  => $totalTokens > 0 ? $totalTokens : null,
            ]);
        }
    }

    /**
     * Handle a coding result — validate and write files to Project Files.
     */
    private function handleCodingResult(RequestContext $ctx, string $userId, array $parsed, string $projectId): void
    {
        $plan = trim((string) ($parsed['plan'] ?? ''));
        if ($plan !== '') {
            SseStreamer::send('progress', ['message' => 'Agent plan: ' . mb_substr($plan, 0, 200)]);
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

    /**
     * Route a conversational request to the local 450M VL.
     */
    private function routeToLocalChat(
        RequestContext $ctx,
        string $userId,
        string $message,
        string $projectId,
        string $activeFile
    ): void {
        $routerUrl = trim((string) ConfigBag::getInstance()->intentRouterUrl());
        if ($routerUrl === '') {
            SseStreamer::send('error', [
                'message' => 'The local AI is not available. Set INTENT_ROUTER_URL in your configuration.',
            ]);
            return;
        }

        $projectContext = $this->buildProjectContext($userId, $projectId, self::LOCAL_CHAT_CHARS);
        $fileContext = $activeFile !== ''
            ? $this->getFileContent($userId, $activeFile, self::ACTIVE_FILE_CHARS)
            : '';

        // Compact system prompt — ~50 tokens vs previous ~100
        $systemPrompt = "You are Galileo, Ashat Hub's AI coding assistant. Be helpful, concise, and technically accurate.";

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

    /**
     * Build the messages for the coding agent generation request.
     *
     * TOKEN OPTIMIZATION: Compact system prompt (~120 tokens vs ~500).
     * Reduced context budgets prevent runaway token usage.
     */
    private function buildCodingMessages(string $normalized, string $projectContext, string $fileContext): array
    {
        // Compact system prompt — ~120 tokens
        $system = "You are ASHAT, an AI coding agent. Build/modify software from natural-language instructions.\n"
            . "Return a JSON object: {\"plan\": \"summary\", \"files\": [{\"path\": \"...\", \"content\": \"...\", \"language\": \"...\"}]}\n"
            . "Rules: production-ready code, relative paths, complete files, no secrets, JSON only.";

        $user = $normalized;
        if ($projectContext !== '') {
            $user = "Project files:\n" . $projectContext . "\n\n" . $user;
        }
        if ($fileContext !== '') {
            $user = "Active file:\n" . $fileContext . "\n\n" . $user;
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    /**
     * Build a project context string from the user's Project Files.
     *
     * TOKEN OPTIMIZATION: Lazy-loads files up to the char budget,
     * never loads the full dataset into memory.
     */
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

                // Truncate each file to max 400 chars in context
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

    /**
     * Get a file's content from Project Files.
     *
     * TOKEN OPTIMIZATION: Caps content at $maxChars to prevent
     * sending huge files to the coding agent.
     */
    private function getFileContent(string $userId, string $filePath, int $maxChars = 3000): string
    {
        try {
            $repo = RepositoryRegistry::file();
            $file = $repo->findByPath($userId, $filePath);
            $content = (string) ($file['content'] ?? '');
            return mb_substr($content, 0, $maxChars);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Estimate token count from messages array.
     * Rough heuristic: ~4 chars per token for English text.
     */
    private function estimateTokens(array $messages): int
    {
        $totalChars = 0;
        foreach ($messages as $m) {
            $totalChars += strlen($m['content'] ?? '');
        }
        return (int) ceil($totalChars / 4);
    }

    /**
     * Normalize a generated path: no traversal, no leading slash.
     */
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
