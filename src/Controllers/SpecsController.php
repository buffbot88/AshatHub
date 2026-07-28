<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\SpecsController — CRUD for specs.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class SpecsController
{
    public function list(RequestContext $ctx): void
    {
        $ctx->jsonResponse(['specs' => RepositoryRegistry::spec()->allForUser((string) $ctx->user()['id'])]);
    }

    public function show(RequestContext $ctx, string $id): void
    {
        $spec = RepositoryRegistry::spec()->findForUser($id, (string) $ctx->user()['id']);
        if (!$spec) $ctx->jsonResponse(['error' => 'not_found'], 404);
        $ctx->jsonResponse(['spec' => $spec]);
    }

    public function create(RequestContext $ctx): void
    {
        $body    = $ctx->jsonBody();
        $title   = trim((string) ($body['title'] ?? $ctx->str('title', 'Untitled Spec')));
        $content = (string) ($body['content'] ?? $ctx->str('content'));
        $default = <<<MD
# Project: $title

## Description
What does this project do?

## Requirements
- [ ] Requirement 1
- [ ] Requirement 2

## Technical Stack
- Language:
- Framework:

## File Structure
- src/

## Acceptance Criteria
- How to verify the build is complete
MD;
        if ($content === '') $content = $default;

        $id = RepositoryRegistry::spec()->create((string) $ctx->user()['id'], $title, $content);
        $ctx->jsonResponse(['spec' => RepositoryRegistry::spec()->find($id)], 201);
    }

    public function update(RequestContext $ctx, string $id): void
    {
        $body    = $ctx->jsonBody();
        $title   = (string) ($body['title'] ?? $ctx->str('title'));
        $content = (string) ($body['content'] ?? $ctx->str('content'));
        $status  = $body['status'] ?? $ctx->input('status') ?? null;

        RepositoryRegistry::spec()->update($id, $title, $content, $status);
        $ctx->jsonResponse(['spec' => RepositoryRegistry::spec()->find($id)]);
    }

    public function delete(RequestContext $ctx, string $id): void
    {
        RepositoryRegistry::spec()->delete($id);
        $ctx->jsonResponse(['deleted' => $id]);
    }
}
