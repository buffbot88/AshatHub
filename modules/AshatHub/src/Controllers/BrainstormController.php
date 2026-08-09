<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Core\SseStreamer;
use Core\SystemValidationEngine;
use Models\ChatBackend;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\BrainstormController — turn an idea into Spec.md + Build.md.
 *
 * Chain routing: every request goes through the Omega slot (the active
 * Neural Host). Beta and Delta slots are disabled (not started / not
 * finished). Slot assignment is 1-to-1-to-1: one project ↔ one Spec.md ↔
 * one Build.md, written to projects/<user>/.
 *
 * Omega's non-streaming path 502s on long generations (60s upstream proxy
 * limit), so the brainstorm streams (stream: true) — long outputs flow as
 * SSE and the accumulated text is parsed for the two documents.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class BrainstormController
{
    /** Omega prompt: produce ONE markdown spec, no JSON wrapper. */
    private const SPEC_PROMPT = <<<'MD'
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
     * Stream the brainstorm through Omega's SSE endpoint (bypasses the 60s
     * non-stream proxy limit). Two sequential single-doc requests — the 1.2B
     * model holds shape better on one doc than on a {spec, build} pair.
     */
    private function streamBrainstorm(RequestContext $ctx, string $idea): void
    {
        $brainstem = RepositoryRegistry::brainstemConfig()->active();
        if (empty($brainstem['api_key'])) {
            SseStreamer::send('error', ['message' => 'No BrainStem (Omega) host is configured for brainstorming.']);
            return;
        }
        $model = trim((string) ($brainstem['model'] ?? ''));
        if ($model === '') {
            $model = ChatBackend::defaultBrainstemId();
        }
        $endpoint = rtrim((string) $brainstem['url'], '/') . '/v1/chat/completions';
        $headers  = [
            'Content-Type: application/json',
            'X-Ashat-Key: ' . $brainstem['api_key'],
        ];

        SseStreamer::send('progress', ['message' => 'Writing the specification (Spec.md)…']);
        $specContent = $this->streamChat($endpoint, $headers, $model, self::SPEC_PROMPT, "Project idea:\n\n" . $idea);
        if ($specContent === null) {
            return; // error event already sent
        }
        $spec = self::docFromJson($specContent, 'spec');
        if ($spec === '') {
            SseStreamer::send('error', ['message' => 'Omega did not produce a usable spec. Raw start: ' . mb_substr($specContent, 0, 300)]);
            return;
        }

        SseStreamer::send('progress', ['message' => 'Writing the build plan (Build.md)…']);
        $buildContent = $this->streamChat($endpoint, $headers, $model, self::BUILD_PROMPT, "Specification:\n\n" . mb_substr($spec, 0, 3000));
        if ($buildContent === null) {
            return;
        }
        $build = self::docFromJson($buildContent, 'build');
        if ($build === '') {
            SseStreamer::send('error', ['message' => 'Omega did not produce a usable build plan. Raw start: ' . mb_substr($buildContent, 0, 300)]);
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
     * One streaming chat round-trip through Omega; returns the accumulated
     * text, or null (an SSE error event was already emitted).
     */
    private function streamChat(string $endpoint, array $headers, string $model, string $system, string $user): ?string
    {
        $payload = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'max_tokens'  => 4096,
            'temperature' => 0.6,
            'stream'      => true,
        ];
        return SseStreamer::proxy($endpoint, $headers, $payload);
    }

    /**
     * Pull a doc out of a model response. The prompts ask for plain markdown,
     * so the raw (fence-stripped) text is the primary result; a JSON wrapper
     * (spec/build key, possibly nested) is decoded when the model emits one.
     */
    private static function docFromJson(string $content, string $key): string
    {
        $parsed = SystemValidationEngine::lenientJson($content);
        if (is_array($parsed)) {
            $doc = trim((string) ($parsed[$key] ?? ''));
            if ($doc !== '') {
                if ($doc[0] !== '{') {
                    return $doc;
                }
                // Nested JSON value (model escaped a doc as an object) — flatten.
                $inner = json_decode($doc, true);
                if (is_array($inner)) {
                    $flat = trim(implode("\n\n", array_map('strval', array_values($inner))));
                    if ($flat !== '') {
                        return $flat;
                    }
                }
            }
        }
        // Strip any fences and use the raw text as the doc.
        $raw = trim($content);
        $raw = preg_replace('/^```(?:json)?\s*\n?/', '', $raw);
        $raw = preg_replace('/\n?```\s*$/', '', $raw);
        $raw = trim($raw);
        if ($raw !== '' && strpos($raw, '"' . $key . '":') !== 0) {
            return $raw;
        }
        return '';
    }
}
