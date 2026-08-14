<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Core\View;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\GalileoStudioController — serves the Galileo Studio page.
 *
 * Galileo Studio is the primary project-development interface for Ashat
 * Hub. It replaces the old Chat Studio / Assistant / Build modes with a
 * single Bolt-style AI development studio centered around conversation.
 *
 * The controller is intentionally thin — it loads project metadata and
 * conversation history, then hands everything to the Vue-free vanilla JS
 * frontend that manages the workspace locally.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GalileoStudioController
{
    /**
     * GET /galileo — render the Galileo Studio page.
     *
     * The page boots with:
     *   - user session info (for the header)
     *   - list of the user's projects (project selector)
     *   - current project files (for the source panel)
     *   - conversation list (for the chat panel)
     */
    public function index(RequestContext $ctx): void
    {
        if (!$ctx->check()) {
            header('Location: /login');
            exit;
        }

        $user = $ctx->user();
        $userId = (string) $user['id'];

        // Load user's projects (from the file-based project system).
        $projects = $this->loadProjects($userId);

        // Load the most recent project or default to empty.
        $currentProjectId = (string) ($_GET['project'] ?? '');
        $currentProject = null;
        if ($currentProjectId !== '' && isset($projects[$currentProjectId])) {
            $currentProject = $projects[$currentProjectId];
        } elseif (!empty($projects)) {
            $currentProject = reset($projects);
            $currentProjectId = $currentProject['id'];
        }

        // Load conversations for the current project.
        $conversations = $currentProjectId !== ''
            ? $this->loadConversations($userId, $currentProjectId)
            : [];

        // Load project files for the source panel.
        $files = $currentProjectId !== ''
            ? $this->loadProjectFiles($userId)
            : [];

        $viewContext = [
            'user'            => $user,
            'csrf'            => $ctx->csrfToken(),
            'projects'        => $projects,
            'currentProject'  => $currentProject,
            'currentProjectId'=> $currentProjectId,
            'conversations'   => $conversations,
            'files'           => $files,
            'version'         => APP_VERSION_DISPLAY,
        ];

        View::render('pages/galileo', $viewContext);
    }

    /**
     * GET /api/galileo/projects — list projects as JSON.
     */
    public function projects(RequestContext $ctx): void
    {
        $userId = (string) $ctx->user()['id'];
        $projects = $this->loadProjects($userId);
        $ctx->jsonResponse(['projects' => array_values($projects)]);
    }

    /**
     * POST /api/galileo/projects — create a new project.
     */
    public function createProject(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            $ctx->jsonResponse(['error' => 'name_required'], 400);
            return;
        }

        $userId = (string) $ctx->user()['id'];
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') $slug = 'project-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $baseDir = ASHAT_ROOT . '/projects/' . $userId;
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }

        $projDir = $baseDir . '/' . $slug;
        if (is_dir($projDir)) {
            $ctx->jsonResponse(['error' => 'project_exists', 'project_id' => $slug]);
            return;
        }

        @mkdir($projDir, 0775, true);

        // Write metadata.
        $meta = [
            'name'        => $name,
            'description' => '',
            'created_at'  => date('c'),
        ];
        file_put_contents($projDir . '/.meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        $ctx->jsonResponse(['ok' => true, 'project_id' => $slug, 'name' => $name], 201);
    }

    /**
     * Load all projects for a user from the filesystem.
     *
     * Each project is a directory under projects/<userId>/.
     * Returns [projectId => [...metadata...], ...].
     */
    private function loadProjects(string $userId): array
    {
        $baseDir = ASHAT_ROOT . '/projects/' . $userId;
        if (!is_dir($baseDir)) {
            // Auto-create the projects directory for new users.
            @mkdir($baseDir, 0775, true);
            return [];
        }

        $projects = [];
        $items = scandir($baseDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || !is_dir($baseDir . '/' . $item)) {
                continue;
            }
            $projectId = $item;
            $projDir = $baseDir . '/' . $projectId;

            // Try to read a project metadata file.
            $meta = $this->readProjectMeta($projDir, $projectId);

            // Count files in the project.
            $fileCount = $this->countProjectFiles($projDir);

            $projects[$projectId] = [
                'id'         => $projectId,
                'name'       => $meta['name'] ?? $projectId,
                'description'=> $meta['description'] ?? '',
                'created_at' => $meta['created_at'] ?? date('c', filemtime($projDir)),
                'file_count' => $fileCount,
            ];
        }

        // Sort by most recently modified.
        uasort($projects, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $projects;
    }

    /**
     * Read project metadata from Spec.md header or a .meta.json file.
     */
    private function readProjectMeta(string $projDir, string $projectId): array
    {
        $metaFile = $projDir . '/.meta.json';
        if (is_file($metaFile)) {
            $data = json_decode(file_get_contents($metaFile), true);
            if (is_array($data)) return $data;
        }

        // Fall back to Spec.md heading as the project name.
        $specFile = $projDir . '/Spec.md';
        if (is_file($specFile)) {
            $fh = fopen($specFile, 'r');
            if ($fh) {
                $firstLine = trim((string) fgets($fh));
                fclose($fh);
                $name = preg_replace('/^#+\s*/', '', $firstLine);
                if ($name !== '') {
                    return ['name' => $name];
                }
            }
        }

        return ['name' => $projectId];
    }

    /**
     * Count files recursively in a project directory.
     */
    private function countProjectFiles(string $dir): int
    {
        $count = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if ($item->isFile()) $count++;
        }
        return $count;
    }

    /**
     * Load conversations for a user+project combination.
     * Uses localStorage on the client side initially; this provides
     * server-side awareness for future migration.
     */
    private function loadConversations(string $userId, string $projectId): array
    {
        try {
            $repo = \Repositories\RepositoryRegistry::conversation();
            return $repo->listByProject($userId, $projectId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Load project files for the source panel.
     * Returns a flat list of file paths and metadata.
     */
    private function loadProjectFiles(string $userId): array
    {
        try {
            $repo = \Repositories\RepositoryRegistry::file();
            $rows = $repo->allWithContent($userId);
            $files = [];
            foreach ($rows as $f) {
                $files[] = [
                    'id'          => $f['id'],
                    'path'        => $f['path'],
                    'language'    => $f['language'] ?? '',
                    'generated'   => !empty($f['generated']),
                    'modified_at' => $f['modified_at'] ?? $f['created_at'] ?? null,
                    'size'        => strlen((string) ($f['content'] ?? '')),
                ];
            }
            return $files;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
