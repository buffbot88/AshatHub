<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Core\SystemValidationEngine;
use Models\ChatBackend;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\GalileoAgentController — Galileo Studio agent adapter.
 *
 * Provides a normalized interface for submitting coding jobs to the
 * Omega/Beta/Delta agent ecosystem and tracking their progress.
 *
 * Galileo communicates with the coding ecosystem through this adapter,
 * which shields the frontend from implementation details of each agent.
 *
 * Endpoints:
 *   POST /api/galileo/agents/jobs        — submit a new coding job
 *   GET  /api/galileo/agents/jobs/:id    — get job status
 *   GET  /api/galileo/agents/jobs/:id/events — stream job events (SSE)
 *   POST /api/galileo/agents/jobs/:id/cancel — cancel a running job
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GalileoAgentController
{
    /** In-memory job store (would be DB-backed in production). */
    private static array $jobs = [];

    /**
     * POST /api/galileo/agents/jobs — submit a coding job.
     *
     * Body: {
     *   "project_id": "...",
     *   "conversation_id": "...",
     *   "request": "Natural language coding request",
     *   "context": { "files": [...], "active_file": "..." }
     * }
     */
    public function createJob(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $request = trim((string) ($body['request'] ?? ''));
        $projectId = trim((string) ($body['project_id'] ?? ''));
        $conversationId = trim((string) ($body['conversation_id'] ?? ''));
        $context = $body['context'] ?? [];

        if ($request === '') {
            $ctx->jsonResponse(['error' => 'request_required'], 400);
            return;
        }

        $userId = (string) $ctx->user()['id'];
        $jobId = 'job_' . bin2hex(random_bytes(6));

        $job = [
            'id'              => $jobId,
            'project_id'      => $projectId,
            'conversation_id' => $conversationId,
            'user_id'         => $userId,
            'request'         => $request,
            'status'          => 'queued',
            'created_at'      => date(DATE_ATOM),
            'updated_at'      => date(DATE_ATOM),
            'result'          => null,
            'files_changed'   => 0,
            'files_created'   => 0,
            'warnings'        => [],
        ];

        self::$jobs[$jobId] = $job;

        // Submit to BrainStem in background (non-blocking).
        $this->dispatchJob($job);

        $ctx->jsonResponse([
            'job' => $this->sanitizeJob($job),
        ], 201);
    }

    /**
     * GET /api/galileo/agents/jobs/:id — get job status.
     */
    public function getJob(RequestContext $ctx, string $id): void
    {
        if (!isset(self::$jobs[$id])) {
            $ctx->jsonResponse(['error' => 'job_not_found'], 404);
            return;
        }

        $ctx->jsonResponse([
            'job' => $this->sanitizeJob(self::$jobs[$id]),
        ]);
    }

    /**
     * GET /api/galileo/agents/jobs/:id/events — SSE stream of job events.
     */
    public function jobEvents(RequestContext $ctx, string $id): void
    {
        if (!isset(self::$jobs[$id])) {
            SseStreamer::headers();
            SseStreamer::send('error', ['message' => 'job_not_found']);
            return;
        }

        SseStreamer::headers();
        $job = self::$jobs[$id];

        // Send current status.
        SseStreamer::send('job.status', [
            'job_id' => $id,
            'status' => $job['status'],
        ]);

        // If already complete, send the result.
        if ($job['status'] === 'complete' || $job['status'] === 'failed') {
            SseStreamer::send('job.' . $job['status'], [
                'job_id' => $id,
                'result' => $job['result'],
            ]);
            return;
        }

        // For queued/running jobs, poll until complete (with timeout).
        $start = time();
        while (time() - $start < 60) {
            usleep(500_000); // 0.5s
            $job = self::$jobs[$id] ?? $job;

            if ($job['status'] === 'complete' || $job['status'] === 'failed') {
                SseStreamer::send('job.' . $job['status'], [
                    'job_id' => $id,
                    'result' => $job['result'],
                ]);
                return;
            }
        }

        SseStreamer::send('job.timeout', ['job_id' => $id]);
    }

    /**
     * POST /api/galileo/agents/jobs/:id/cancel — cancel a running job.
     */
    public function cancelJob(RequestContext $ctx, string $id): void
    {
        if (!isset(self::$jobs[$id])) {
            $ctx->jsonResponse(['error' => 'job_not_found'], 404);
            return;
        }

        $job = &self::$jobs[$id];
        if ($job['status'] === 'complete' || $job['status'] === 'failed' || $job['status'] === 'cancelled') {
            $ctx->jsonResponse(['error' => 'job_not_cancelable'], 400);
            return;
        }

        $job['status'] = 'cancelled';
        $job['updated_at'] = date(DATE_ATOM);

        $ctx->jsonResponse(['ok' => true]);
    }

    /**
     * Dispatch a job to the coding agent ecosystem (BrainStem/Omega).
     */
    private function dispatchJob(array $job): void
    {
        $brainstem = RepositoryRegistry::brainstemConfig()->active();
        if (empty($brainstem['api_key'])) {
            self::$jobs[$job['id']]['status'] = 'failed';
            self::$jobs[$job['id']]['result'] = [
                'error' => 'No coding agent is configured.',
            ];
            return;
        }

        $userId = $job['user_id'];

        // Build context.
        $projectContext = $this->buildProjectContext($userId);

        // Normalize the request.
        $normalized = $this->normalizeRequest($job['request'], $projectContext);

        // Build messages.
        $messages = $this->buildMessages($normalized, $projectContext);

        // Mark as working.
        self::$jobs[$job['id']]['status'] = 'working';
        self::$jobs[$job['id']]['updated_at'] = date(DATE_ATOM);

        // Call the coding agent.
        $backend = ChatBackend::select($brainstem, null, '');
        $req = $backend->buildRequest($messages, [
            'max_tokens' => 16384,
            'temperature' => 0.6,
        ], false);

        $resp = SystemValidationEngine::postJson($req['endpoint'], $req['headers'], $req['payload']);

        if ($resp === null) {
            self::$jobs[$job['id']]['status'] = 'failed';
            self::$jobs[$job['id']]['result'] = ['error' => 'Coding agent did not respond.'];
            self::$jobs[$job['id']]['updated_at'] = date(DATE_ATOM);
            return;
        }

        $content = $resp['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            self::$jobs[$job['id']]['status'] = 'failed';
            self::$jobs[$job['id']]['result'] = ['error' => 'Empty response from coding agent.'];
            self::$jobs[$job['id']]['updated_at'] = date(DATE_ATOM);
            return;
        }

        // Parse and apply result.
        $parsed = SystemValidationEngine::lenientJson($content);

        if (is_array($parsed) && !empty($parsed['files']) && is_array($parsed['files'])) {
            // Write files.
            $saved = $this->writeFiles($userId, $parsed['files']);
            self::$jobs[$job['id']]['status'] = 'complete';
            self::$jobs[$job['id']]['files_changed'] = $saved['changed'];
            self::$jobs[$job['id']]['files_created'] = $saved['created'];
            self::$jobs[$job['id']]['result'] = [
                'plan'      => $parsed['plan'] ?? '',
                'files'     => $saved['paths'],
                'saved'     => $saved['total'],
            ];
        } else {
            self::$jobs[$job['id']]['status'] = 'complete';
            self::$jobs[$job['id']]['result'] = [
                'content' => $content,
                'type'    => 'conversation',
            ];
        }

        self::$jobs[$job['id']]['updated_at'] = date(DATE_ATOM);
    }

    /**
     * Write generated files to Project Files.
     */
    private function writeFiles(string $userId, array $files): array
    {
        $repo = RepositoryRegistry::file();
        $total = 0;
        $created = 0;
        $changed = 0;
        $paths = [];

        foreach ($files as $f) {
            if (!is_array($f) || !isset($f['path']) || !isset($f['content'])) continue;
            $path = $this->sanitizePath((string) $f['path']);
            if ($path === '') continue;
            $content = (string) $f['content'];
            if (strlen($content) > 250 * 1024) continue;

            $existing = $repo->findByPath($userId, $path);
            $isUpdate = $existing !== null;

            try {
                $lang = \Core\LanguageDetector::detect($path);
                $repo->save($userId, $path, $content, $lang, true);
                $total++;
                if ($isUpdate) $changed++; else $created++;
                $paths[] = $path;
            } catch (\Throwable $e) {
                // Skip files that can't be saved.
            }
        }

        return ['total' => $total, 'created' => $created, 'changed' => $changed, 'paths' => $paths];
    }

    /**
     * Build project context from user's files.
     */
    private function buildProjectContext(string $userId): string
    {
        try {
            $repo = RepositoryRegistry::file();
            $rows = $repo->allWithContent($userId);
            $context = '';
            foreach ($rows as $f) {
                $content = (string) ($f['content'] ?? '');
                if ($content === '') continue;
                $entry = "--- {$f['path']} ---\n" . mb_substr($content, 0, 800) . "\n\n";
                if (strlen($context) + strlen($entry) > 5000) break;
                $context .= $entry;
            }
            return $context;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Normalize a coding request via the local 450M VL.
     */
    private function normalizeRequest(string $request, string $projectContext): string
    {
        $routerUrl = trim((string) ConfigBag::getInstance()->intentRouterUrl());
        if ($routerUrl === '') return $request;

        $system = "You are a prompt normalizer. Rewrite the user's coding request into a clear, "
            . "detailed instruction. Include relevant context. Output ONLY the normalized request.";

        $user = $request;
        if ($projectContext !== '') {
            $user = "Project:\n" . mb_substr($projectContext, 0, 1500) . "\n\n" . $user;
        }

        $normalized = ChatBackend::localVL(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            1024
        );

        return $normalized !== null && trim($normalized) !== '' ? $normalized : $request;
    }

    /**
     * Build the messages for the coding agent.
     */
    private function buildMessages(string $normalized, string $projectContext): array
    {
        $system = "You are ASHAT, an AI coding agent. Build and modify software from instructions.\n\n"
            . "Return a JSON object:\n"
            . '{"plan":"summary","files":[{"path":"relative/path","content":"complete file","language":"lang"}]}\n\n'
            . "Rules:\n- Production-ready code\n- Relative paths\n- Include README.md for new projects\n- No secrets\n- Output ONLY JSON";

        $user = $normalized;
        if ($projectContext !== '') {
            $user = "Existing files:\n" . mb_substr($projectContext, 0, 3000) . "\n\n" . $user;
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    /**
     * Sanitize a file path.
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

    /**
     * Sanitize a job for client response (strip internal fields).
     */
    private function sanitizeJob(array $job): array
    {
        return [
            'id'              => $job['id'],
            'project_id'      => $job['project_id'],
            'conversation_id' => $job['conversation_id'],
            'request'         => $job['request'],
            'status'          => $job['status'],
            'created_at'      => $job['created_at'],
            'updated_at'      => $job['updated_at'],
            'result'          => $job['result'],
            'files_changed'   => $job['files_changed'],
            'files_created'   => $job['files_created'],
            'warnings'        => $job['warnings'],
        ];
    }
}
