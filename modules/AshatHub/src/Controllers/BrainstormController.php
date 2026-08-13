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
 * Controllers\BrainstormController — turn an idea into Spec.md + Build.md.
 *
 * The brainstorm runs on the LOCAL 450M VL (Intent Router) — the remote
 * hosts (Omega/Beta/Delta) are code-generation agents, not chat bots, so
 * conversation, spec-writing, and build planning never leave the box.
 * Slot assignment is 1-to-1-to-1: one project ↔ one Spec.md ↔ one
 * Build.md, written to projects/<user>/. Both docs are artifacts, never
 * a gate — builds accept natural language directly.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class BrainstormController
{
    /** Prompt: produce ONE markdown spec, no JSON wrapper (also used by BuildPipelineController). */
    public const SPEC_PROMPT = <<<'MD'
You are ASHAT, a software architect. Given a project idea, write a concise markdown SPECIFICATION with exactly these sections:

# Project: <title>
## Overview
## Requirements
## Technical Stack
## File Structure
## Acceptance Criteria

Output ONLY the markdown document itself — no JSON wrapper, no code fences, no commentary, no headings about the format.
MD;

    /** Omega prompt: produce ONE markdown build plan, no JSON wrapper. */
    private const BUILD_PROMPT = <<<'MD'
You are ASHAT, a build planner. Given a specification, write a BUILD PLAN in markdown with exactly these sections:

## Build Order
## Tasks
## Risks
## Verification

Output ONLY the markdown document itself — no JSON wrapper, no code fences, no commentary, no headings about the format.
MD;

    public function run(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $idea = trim((string) ($body['idea'] ?? ''));
        if (function_exists('set_time_limit')) {
            set_time_limit(900);
        }
        SseStreamer::headers();
        try {
            if ($idea === '') {
                SseStreamer::send('error', ['message' => 'idea_required']);
                return;
            }
            $this->streamBrainstorm($ctx, $idea);
        } catch (\Throwable $e) {
            try {
                SseStreamer::send('error', ['message' => 'Brainstorm failed: ' . $e->getMessage()]);
            } catch (\Throwable $ignored) {
                // SSE stream already closed — nothing we can do.
            }
        }
    }

    /**
     * Stream the brainstorm through the local 450M VL (Intent Router).
     * Two sequential single-doc requests — the model holds shape better
     * on one doc than on a {spec, build} pair.
     */
    private function streamBrainstorm(RequestContext $ctx, string $idea): void
    {
        $routerUrl = rtrim((string) ConfigBag::getInstance()->intentRouterUrl(), '/');
        if ($routerUrl === '') {
            SseStreamer::send('error', ['message' => 'No local Intent Router is configured for brainstorming. Set INTENT_ROUTER_URL.']);
            return;
        }
        $backend = ChatBackend::select(null, null, 'local');
        if (!$backend->isAvailable()) {
            SseStreamer::send('error', ['message' => 'Local 450M VL is not available for brainstorming.']);
            return;
        }

        SseStreamer::send('progress', ['message' => 'Writing the specification (Spec.md)…']);
        $specContent = $this->localChat($backend, self::SPEC_PROMPT, "Project idea:\n\n" . $idea);
        if ($specContent === null) {
            SseStreamer::send('error', ['message' => 'Local 450M VL did not respond for the spec. Try again.']);
            return;
        }
        $spec = self::docFromJson($specContent, 'spec');
        if ($spec === '') {
            SseStreamer::send('error', ['message' => 'Local 450M VL did not produce a usable spec. Raw start: ' . mb_substr($specContent, 0, 300)]);
            return;
        }

        SseStreamer::send('progress', ['message' => 'Writing the build plan (Build.md)…']);
        $buildContent = $this->localChat($backend, self::BUILD_PROMPT, "Specification:\n\n" . mb_substr($spec, 0, 3000));
        if ($buildContent === null) {
            SseStreamer::send('error', ['message' => 'Local 450M VL did not respond for the build plan. Try again.']);
            return;
        }
        $build = self::docFromJson($buildContent, 'build');
        if ($build === '') {
            SseStreamer::send('error', ['message' => 'Local 450M VL did not produce a usable build plan. Raw start: ' . mb_substr($buildContent, 0, 300)]);
            return;
        }

        // 1-to-1-to-1 slot: exactly one Spec.md + one Build.md per project.
        $userId = (string) $ctx->user()['id'];
        $dir    = ashat_user_project_dir($userId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/Spec.md', $spec);
        file_put_contents($dir . '/Build.md', $build);

        // Mirror into the DB so file-manager ops (delete/rename) have ids.
        $repo = RepositoryRegistry::file();
        $repo->save($userId, 'Spec.md', $spec, 'markdown');
        $repo->save($userId, 'Build.md', $build, 'markdown');

        SseStreamer::send('done', [
            'ok'    => true,
            'spec'  => $spec,
            'build' => $build,
            'paths' => ['Spec.md', 'Build.md'],
        ]);
    }

    /**
     * One local round-trip through the 450M VL (Intent Router) — shared
     * ChatBackend::localVL (retries transient 429/5xx). Returns the
     * accumulated text, or null on failure.
     */
    private function localChat(ChatBackend $backend, string $system, string $user): ?string
    {
        return ChatBackend::localVL(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            1500
        );
    }

    /** Pull a doc out of a model response (shared SystemValidationEngine helper). */
    private static function docFromJson(string $content, string $key): string
    {
        return SystemValidationEngine::docFromMarkdown($content, $key);
    }
}
