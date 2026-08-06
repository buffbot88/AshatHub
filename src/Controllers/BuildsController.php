<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Models\BuildPayload;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\BuildsController — build lifecycle + metadata validation.
 *
 * Local-first: the browser keeps the full {plan, files[]} payload in
 * localStorage and POSTs only metadata here — the server never receives
 * API keys or generated source code.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class BuildsController
{
    public function list(RequestContext $ctx): void
    {
        $ctx->jsonResponse(['builds' => RepositoryRegistry::build()->allForUser((string) $ctx->user()['id'])]);
    }

    public function create(RequestContext $ctx): void
    {
        $body   = $ctx->jsonBody();
        $specId = (string) ($body['spec_id'] ?? $ctx->str('spec_id'));
        $spec   = RepositoryRegistry::spec()->find($specId);
        if (!$spec) $ctx->jsonResponse(['error' => 'spec_not_found'], 404);

        $agentPlan  = isset($body['plan']) ? trim((string) $body['plan']) : '';
        $agentPaths = (isset($body['file_paths']) && is_array($body['file_paths'])) ? $body['file_paths'] : [];
        $clientId   = isset($body['id']) ? trim((string) $body['id']) : null;

        $payload = BuildPayload::fromRequest($agentPlan, $agentPaths);
        if ($payload->failed()) $ctx->jsonResponse(['error' => $payload->error()], 422);

        $phaseTree = [
            'phases' => array_map(static fn ($f) => ['path' => $f['path'], 'status' => 'ok'], $payload->paths()),
            'note'   => 'File content lives in browser localStorage (local-first).',
        ];

        $consoleLogs = [
            ['type' => 'info', 'message' => '🚀 Starting build for ' . $spec['title'],                    'ts' => date(DATE_ATOM)],
            ['type' => 'plan', 'message' => '📋 Agent generated ' . count($payload->paths()) . ' file(s); content stored locally.', 'ts' => date(DATE_ATOM)],
        ];

        $buildId = RepositoryRegistry::build()->create(
            (string) $ctx->user()['id'],
            $spec['id'],
            $spec['title'],
            $payload->plan(),
            $phaseTree,
            $consoleLogs,
            $clientId
        );

        foreach ($payload->paths() as $f) {
            RepositoryRegistry::file()->save(
                (string) $ctx->user()['id'],
                $f['path'],
                null,
                $f['language'],
                true,
                $buildId,
                'agent'
            );
        }

        RepositoryRegistry::build()->complete(
            $buildId,
            (string) $ctx->user()['id'],
            $payload->plan(),
            array_map(static fn ($f) => ['path' => $f['path']], $payload->paths())
        );

        $ctx->jsonResponse([
            'build' => RepositoryRegistry::build()->find($buildId, (string) $ctx->user()['id']),
            'plan'  => $payload->plan(),
        ], 201);
    }

    public function approve(RequestContext $ctx, string $id): void
    {
        RepositoryRegistry::build()->approve($id, (string) $ctx->user()['id']);
        $build = RepositoryRegistry::build()->find($id, (string) $ctx->user()['id']);
        $ctx->jsonResponse(['build' => $build]);
    }


}
