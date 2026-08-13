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
 * Handles the primary conversational interface for Galileo Studio.
 * Routes user messages to either:
 *   1. Local 450M VL (Intent Router) — for conversation, project
 *      questions, brainstorming, prompt normalization
 *   2. Coding Agent Ecosystem (Omega/Beta/Delta) — for code generation,
 *      debugging, refactoring, and other software-engineering tasks
 *
 * The user never sees the routing decision. Galileo classifies the
 * request internally and dispatches accordingly.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GalileoChatController
{
    /**
     * POST /api/galileo/chat — SSE. Main chat endpoint.
     *
     * Body: {
     *   "project_id": "...",
     *   "conversation_id": "...",
     *   "message": "...",
     *   "active_file": "...",
     *   "selection": null
     * }
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
     * Returns one of: conversation, project_question, brainstorm,
     * coding_request, debug, review, refactor, preview_issue, file_operation
     */
    private function classifyIntent(string $userId, string $message, string $projectId): string
    {
        $routerUrl = trim((string) ConfigBag::getInstance()->intentRouterUrl());
        if ($routerUrl === '') {
            // No local router — default to coding request (safest assumption).
            return 'coding_request';
        }

        $projectContext = $this->buildProjectContext($userId, $projectId);

        $systemPrompt = "You are Galileo Studio's intent classifier. Given a user message "
            . "and optional project context, classify the intent into exactly ONE category:\n\n"
            . "- conversation: general chat, greetings, opinions, non-coding questions\n"
            . "- project_question: asking about the current project (what it does, how it works)\n"
            . "- brainstorm: exploring ideas, planning features, discussing architecture\n"
            . "- coding_request: needs code written, changed, created, or built\n"
            . "- debug: something is broken and needs fixing\n"
            . "- review: wants code reviewed or explained\n"
            . "- refactor: wants existing code restructured\n"
            . "- preview_issue: something wrong with the preview/runtime\n"
            . "- file_operation: needs to create, rename, delete, or move files\n\n"
            . "Reply with ONLY the category name, nothing else.";

        $userPrompt = $message;
        if ($projectContext !== '') {
            $userPrompt = "Project context:\n" . mb_substr($projectContext, 0, 1500) . "\n\nUser message:\n" . $message;
        }

        $content = ChatBackend::localVL(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            50
        );

        if ($content === null) {
            return 'coding_request'; // Default
        }

        $intent = strtolower(trim($content));
        $validIntents = ['conversation', 'project_question', 'brainstorm', 'coding_request',
                         'debug', 'review', 'refactor', 'preview_issue', 'file_operation'];

        return in_array($intent, $validIntents, true) ? $intent : 'coding_request';
    }

    /**
     * Route a coding request to the Omega/Beta/Delta agent ecosystem.
     * This submits a job and streams agent progress back to the client.
     */
    private function routeToCodingAgent(
        RequestContext $ctx,
        string $userId,
        string $message,
        string $projectId,
        string $activeFile
    ): void {
        SseStreamer::send('progress', ['message' => 'Analyzing your request...']);

        // Build project context for the coding agent.
        $projectContext = $this->buildProjectContext($userId, $projectId);
        $fileContext = $activeFile !== '' ? $this->getFileContent($userId, $activeFile) : '';

        // Use the raw message — the coding agent handles its own prompt construction.
        // (Skipping the extra normalizePrompt VL call to keep latency low.)
        $normalized = $message;

        SseStreamer::send('progress', ['message' => 'Submitting to coding agent...']);

        // Submit the coding job to BrainStem (Omega).
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

        // Build the generation request.
        $messages = $this->buildCodingMessages($normalized, $projectContext, $fileContext);
        $req = $backend->buildRequest($messages, [
            'max_tokens' => 16384,
            'temperature' => 0.6,
        ], false);

        SseStreamer::send('progress', ['message' => 'Agent is working...']);

        $resp = SystemValidationEngine::postJson($req['endpoint'], $req['headers'], $req['payload']);
        if ($resp === null) {
            SseStreamer::send('error', ['message' => 'The coding agent did not respond. It may be temporarily unavailable.']);
            return;
        }

        $content = $resp['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            SseStreamer::send('error', ['message' => 'The coding agent returned an empty response.']);
            return;
        }

        // Parse the response — may contain files, plan, and/or conversation.
        $parsed = SystemValidationEngine::lenientJson($content);

        if (is_array($parsed) && !empty($parsed['files']) && is_array($parsed['files'])) {
            // Code generation response — write files.
            $this->handleCodingResult($ctx, $userId, $parsed, $projectId);
        } else {
            // Conversation response — just display it.
            SseStreamer::send('done', [
                'type'    => 'conversation',
                'content' => $content,
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

        $projectContext = $this->buildProjectContext($userId, $projectId);
        $fileContext = $activeFile !== '' ? $this->getFileContent($userId, $activeFile) : '';

        $systemPrompt = "You are Galileo, the AI assistant inside Ashat Hub's Galileo Studio. "
            . "You help users build software by understanding their ideas, answering questions, "
            . "and discussing their projects. Be helpful, concise, and technically accurate. "
            . "You can help brainstorm features, explain code, and suggest improvements.";

        $userPrompt = $message;
        if ($projectContext !== '') {
            $userPrompt = "Project context:\n" . mb_substr($projectContext, 0, 2000) . "\n\n" . $userPrompt;
        }
        if ($fileContext !== '') {
            $userPrompt = "Active file:\n" . mb_substr($fileContext, 0, 2000) . "\n\n" . $userPrompt;
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
     * Normalize a user prompt via the local 450M VL for the coding agent.
     */
    private function normalizePrompt(
        string $userId,
        string $message,
        string $projectContext,
        string $fileContext
    ): string {
        $routerUrl = trim((string) ConfigBag::getInstance()->intentRouterUrl());
        if ($routerUrl === '') {
            return $message;
        }

        $system = "You are Galileo's prompt normalizer. Rewrite the user's request into a clear, "
            . "detailed coding instruction. Include relevant context from the project. "
            . "Output ONLY the normalized instruction, nothing else.";

        $user = $message;
        if ($projectContext !== '') {
            $user = "Project files:\n" . mb_substr($projectContext, 0, 1500) . "\n\n" . $user;
        }
        if ($fileContext !== '') {
            $user = "Active file content:\n" . mb_substr($fileContext, 0, 1500) . "\n\n" . $user;
        }

        $normalized = ChatBackend::localVL(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            1024
        );

        return $normalized !== null && trim($normalized) !== '' ? $normalized : $message;
    }

    /**
     * Build the messages for the coding agent generation request.
     */
    private function buildCodingMessages(string $normalized, string $projectContext, string $fileContext): array
    {
        $system = [
            'You are ASHAT, an AI coding agent. You build and modify software from natural-language instructions.',
            '',
            'Given a user request, you must:',
            '1. Understand what the user wants.',
            '2. Create or modify the necessary files with complete, working code.',
            '3. Return a single JSON object with this shape:',
            '',
            '{',
            '  "plan": "A 1-3 sentence summary of what you will build/change.",',
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
            '- If modifying existing files, include the COMPLETE updated file.',
            '- Include a README.md when creating a new project.',
            '- Do NOT include private keys, real credentials, or secrets.',
            '- Output ONLY the JSON object. No prose, no code fences.',
        ];

        $user = $normalized;
        if ($projectContext !== '') {
            $user = "Existing project files:\n" . mb_substr($projectContext, 0, 3000) . "\n\n" . $user;
        }
        if ($fileContext !== '') {
            $user = "Active file:\n" . mb_substr($fileContext, 0, 2000) . "\n\n" . $user;
        }

        return [
            ['role' => 'system', 'content' => implode("\n", $system)],
            ['role' => 'user',   'content' => $user],
        ];
    }

    /**
     * Build a project context string from the user's Project Files.
     */
    private function buildProjectContext(string $userId, string $projectId): string
    {
        try {
            $repo = RepositoryRegistry::file();
            $rows = $repo->allWithContent($userId);
            $context = '';
            $budget = 5000;

            foreach ($rows as $f) {
                $path = $f['path'];
                $content = (string) ($f['content'] ?? '');
                if ($content === '') continue;

                $entry = "--- $path ---\n" . mb_substr($content, 0, 800) . "\n\n";
                if (strlen($context) + strlen($entry) > $budget) break;
                $context .= $entry;
            }

            return $context;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Get a file's content from Project Files.
     */
    private function getFileContent(string $userId, string $filePath): string
    {
        try {
            $repo = RepositoryRegistry::file();
            $file = $repo->findByPath($userId, $filePath);
            return (string) ($file['content'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Normalize a generated path: no traversal, no leading slash.
     */
    private function sanitizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('/^\.?\//', '', $path);
        $path = preg_replace('/(?:^|\/)\.\.($|\/)/', '/', $path);
        $path = preg_replace('/\/{2,}/', '/', $path);
        $path = preg_replace('/[\x00-\x1f]/', '', $path);
        return trim($path, '/');
    }
}
