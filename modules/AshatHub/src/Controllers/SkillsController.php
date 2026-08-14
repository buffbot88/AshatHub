<?php
declare(strict_types=1);
namespace Controllers;

use Core\Database;
use Core\RequestContext;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\SkillsController — skills database API for coding agents.
 *
 * Provides a simple REST API for Omega/Beta/Delta to look up skills:
 *   GET  /api/skills?name=php-classes      — exact lookup
 *   GET  /api/skills?category=php          — list by category
 *   GET  /api/skills?q=vbulletin           — search by keyword
 *   GET  /api/skills                       — list all (with limit)
 *
 * The 450M VL seeds the coding agent's workspace by looking up relevant
 * skills and injecting them into the prompt.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class SkillsController
{
    /**
     * GET /api/skills — lookup skills.
     */
    public function index(RequestContext $ctx): void
    {
        header('Content-Type: application/json');

        $pdo = Database::connection();
        $name = trim((string) ($_GET['name'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $q = trim((string) ($_GET['q'] ?? ''));
        $limit = min(50, max(1, (int) ($_GET['limit'] ?? 10)));

        // Exact name lookup
        if ($name !== '') {
            $stmt = $pdo->prepare('SELECT name, category, content, tokens_estimated FROM agent_skills WHERE name = ?');
            $stmt->execute([$name]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                echo json_encode(['ok' => true, 'skill' => $row]);
            } else {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'skill_not_found']);
            }
            return;
        }

        // Category filter
        if ($category !== '') {
            $stmt = $pdo->prepare('SELECT name, category, tokens_estimated FROM agent_skills WHERE category = ? ORDER BY name LIMIT ?');
            $stmt->execute([$category, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'skills' => $rows, 'count' => count($rows)]);
            return;
        }

        // Keyword search
        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare('SELECT name, category, tokens_estimated FROM agent_skills WHERE name LIKE ? OR content LIKE ? ORDER BY name LIMIT ?');
            $stmt->execute([$like, $like, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'skills' => $rows, 'count' => count($rows)]);
            return;
        }

        // List all
        $stmt = $pdo->prepare('SELECT name, category, tokens_estimated FROM agent_skills ORDER BY category, name LIMIT ?');
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'skills' => $rows, 'count' => count($rows)]);
    }

    /**
     * POST /api/skills — create or update a skill (admin only).
     */
    public function store(RequestContext $ctx): void
    {
        header('Content-Type: application/json');

        if (!$ctx->check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
            return;
        }

        $body = $ctx->jsonBody();
        $name = trim((string) ($body['name'] ?? ''));
        $category = trim((string) ($body['category'] ?? 'general'));
        $content = (string) ($body['content'] ?? '');

        if ($name === '' || $content === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'name and content required']);
            return;
        }

        $tokensEst = (int) ceil(strlen($content) / 4);

        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO agent_skills (name, category, content, tokens_estimated) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content), category = VALUES(category), tokens_estimated = VALUES(tokens_estimated)');
        $stmt->execute([$name, $category, $content, $tokensEst]);

        echo json_encode(['ok' => true, 'name' => $name, 'tokens_estimated' => $tokensEst]);
    }

    /**
     * DELETE /api/skills/{name} — remove a skill (admin only).
     */
    public function delete(RequestContext $ctx, string $name): void
    {
        header('Content-Type: application/json');

        if (!$ctx->check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM agent_skills WHERE name = ?');
        $stmt->execute([$name]);

        echo json_encode(['ok' => true, 'deleted' => $name]);
    }
}
