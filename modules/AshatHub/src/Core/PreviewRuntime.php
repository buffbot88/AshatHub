<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\PreviewRuntime — Process manager for live project previews.
 *
 * Each project preview runs as an isolated dev server process:
 *   1. Project files are materialised from the DB to a temp directory
 *   2. If package.json exists → npm install + npm run dev (Vite)
 *   3. Otherwise → lightweight static file server (Node one-liner)
 *   4. The preview is accessible at /preview/{userId}/{projectId}/
 *
 * State is persisted to a JSON file so it survives individual PHP
 * request boundaries (unlike in-memory arrays).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PreviewRuntime
{
    private const BASE_DIR    = '/home/opc/AshatPlatform/modules/AshatHub/previews';
    private const STATE_FILE  = '/home/opc/AshatPlatform/modules/AshatHub/previews/.state.json';
    private const PORT_RANGE  = [51800, 51899]; // random ports in this range
    private const START_TIMEOUT = 15;           // seconds to wait for server
    private const LOG_MAX_BYTES = 64 * 1024;    // 64 KB log tail

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Start a preview for the given user + project.
     *
     * Returns ['status' => 'running'|'error', 'url' => '...', 'port' => int] or
     *         ['status' => 'error', 'error' => '...'].
     */
    public static function start(string $userId, string $projectId): array
    {
        $key = self::key($userId, $projectId);

        // Already running?
        $existing = self::loadState($key);
        if ($existing !== null && $existing['status'] === 'running') {
            if (self::isAlive((int) $existing['pid'])) {
                return [
                    'status' => 'running',
                    'url'    => $existing['url'],
                    'port'   => (int) $existing['port'],
                ];
            }
            // Stale — clean up and restart.
            self::cleanup($existing);
        }

        // Materialise project files to disk.
        $dir = self::projectDir($userId, $projectId);
        self::materialiseFiles($userId, $projectId, $dir);

        // Detect project type.
        $type = self::detectType($dir);

        // Pick a port.
        $port = self::pickPort();
        if ($port === 0) {
            return ['status' => 'error', 'error' => 'No available preview ports.'];
        }

        // Start the server.
        $result = match ($type) {
            'vite'    => self::startVite($dir, $port),
            'static'  => self::startStatic($dir, $port),
            default   => ['ok' => false, 'error' => 'Unknown project type.'],
        };

        if (!$result['ok']) {
            return ['status' => 'error', 'error' => $result['error'] ?? 'Failed to start preview.'];
        }

        $pid   = $result['pid'];
        $url   = "http://127.0.0.1:{$port}";

        // Wait for server to be ready.
        $ready = self::waitForReady($port, self::START_TIMEOUT);

        $state = [
            'user_id'    => $userId,
            'project_id' => $projectId,
            'port'       => $port,
            'pid'        => $pid,
            'type'       => $type,
            'status'     => $ready ? 'running' : 'starting',
            'url'        => $url,
            'started_at' => date(DATE_ATOM),
            'log_file'   => $dir . '/.preview.log',
        ];

        self::saveState($key, $state);

        return [
            'status' => $state['status'],
            'url'    => $url,
            'port'   => $port,
        ];
    }

    /**
     * Stop a running preview.
     */
    public static function stop(string $userId, string $projectId): array
    {
        $key   = self::key($userId, $projectId);
        $state = self::loadState($key);

        if ($state === null) {
            return ['ok' => true, 'message' => 'Preview was not running.'];
        }

        self::cleanup($state);
        self::deleteState($key);

        return ['ok' => true];
    }

    /**
     * Restart a preview (stop + start).
     */
    public static function restart(string $userId, string $projectId): array
    {
        self::stop($userId, $projectId);
        return self::start($userId, $projectId);
    }

    /**
     * Get the current status of a preview.
     */
    public static function status(string $userId, string $projectId): array
    {
        $key   = self::key($userId, $projectId);
        $state = self::loadState($key);

        if ($state === null) {
            return ['status' => 'stopped', 'url' => null, 'port' => null];
        }

        // Verify the process is still alive.
        if ($state['status'] === 'running' && !self::isAlive((int) $state['pid'])) {
            self::cleanup($state);
            self::deleteState($key);
            return ['status' => 'crashed', 'url' => null, 'port' => null];
        }

        return [
            'status'     => $state['status'],
            'url'        => $state['url'],
            'port'       => (int) $state['port'],
            'type'       => $state['type'],
            'started_at' => $state['started_at'] ?? null,
        ];
    }

    /**
     * Get the log tail for a preview.
     */
    public static function getLog(string $userId, string $projectId, int $maxBytes = 0): string
    {
        $key   = self::key($userId, $projectId);
        $state = self::loadState($key);
        if ($state === null) return '';

        $logFile = $state['log_file'] ?? '';
        if ($logFile === '' || !is_file($logFile)) return '';

        $max = $maxBytes > 0 ? $maxBytes : self::LOG_MAX_BYTES;
        $size = filesize($logFile);
        if ($size <= $max) {
            return (string) file_get_contents($logFile);
        }
        // Tail the last $max bytes.
        $fp = fopen($logFile, 'r');
        fseek($fp, -$max, SEEK_END);
        return (string) fread($fp, $max);
    }

    /**
     * Get the filesystem path for a running preview's served directory.
     * Used by the preview proxy to serve static assets.
     */
    public static function getServedDir(string $userId, string $projectId): ?string
    {
        $key   = self::key($userId, $projectId);
        $state = self::loadState($key);
        if ($state === null || $state['status'] !== 'running') return null;
        return self::projectDir($userId, $projectId);
    }

    /**
     * Find which user/project owns a given port (for the proxy).
     */
    public static function findByPort(int $port): ?array
    {
        $states = self::loadAllStates();
        foreach ($states as $key => $state) {
            if ((int) ($state['port'] ?? 0) === $port) {
                return $state;
            }
        }
        return null;
    }

    // ── Private helpers ──────────────────────────────────────────────

    private static function key(string $userId, string $projectId): string
    {
        return $userId . ':' . $projectId;
    }

    private static function projectDir(string $userId, string $projectId): string
    {
        return self::BASE_DIR . '/' . rawurlencode($userId) . '/' . rawurlencode($projectId);
    }

    /**
     * Materialise files from the DB to disk.
     */
    private static function materialiseFiles(string $userId, string $projectId, string $dir): void
    {
        // Ensure directory exists.
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Load files from DB.
        try {
            $repo = \Repositories\RepositoryRegistry::file();
            $rows = $repo->allWithContent($userId);
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $file) {
            $path    = $file['path'] ?? '';
            $content = $file['content'] ?? '';
            if ($path === '' || $content === '') continue;

            // Sanitise path.
            $path = str_replace('\\', '/', $path);
            $path = preg_replace('#\.{2,}#', '', $path);
            $safePath = $dir . '/' . $path;

            $parent = dirname($safePath);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }

            file_put_contents($safePath, $content);
        }
    }

    /**
     * Detect whether a project is Vite-based or static.
     */
    private static function detectType(string $dir): string
    {
        if (is_file($dir . '/package.json')) {
            $pkg = @json_decode((string) file_get_contents($dir . '/package.json'), true);
            if (is_array($pkg)) {
                $allDeps = array_merge(
                    $pkg['dependencies'] ?? [],
                    $pkg['devDependencies'] ?? []
                );
                if (isset($allDeps['vite']) || isset($allDeps['@vitejs/plugin-react'])) {
                    return 'vite';
                }
                // Any npm project with a dev script.
                if (isset($pkg['scripts']['dev'])) {
                    return 'vite';
                }
            }
        }
        return 'static';
    }

    /**
     * Start a Vite dev server.
     */
    private static function startVite(string $dir, int $port): array
    {
        $logFile = $dir . '/.preview.log';

        // npm install (if node_modules missing).
        if (!is_dir($dir . '/node_modules')) {
            $installLog = $logFile . '.install';
            exec(
                "cd " . escapeshellarg($dir) . " && npm install --prefer-offline 2>&1 > " . escapeshellarg($installLog),
                $installOutput,
                $installExit
            );
            if ($installExit !== 0) {
                return ['ok' => false, 'error' => 'npm install failed. Check package.json.'];
            }
        }

        // Start vite dev server.
        $cmd = sprintf(
            'cd %s && nohup npx vite --host 0.0.0.0 --port %d --strictPort > %s 2>&1 & echo $!',
            escapeshellarg($dir),
            $port,
            escapeshellarg($logFile)
        );

        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) {
            return ['ok' => false, 'error' => 'Failed to start Vite dev server.'];
        }

        return ['ok' => true, 'pid' => $pid];
    }

    /**
     * Start a lightweight static file server (for plain HTML/CSS/JS projects).
     */
    private static function startStatic(string $dir, int $port): array
    {
        $logFile = $dir . '/.preview.log';

        // Use a tiny Node static server.
        $serverScript = $dir . '/.__preview_server.mjs';
        file_put_contents($serverScript, self::staticServerScript());

        $cmd = sprintf(
            'cd %s && nohup node %s %d > %s 2>&1 & echo $!',
            escapeshellarg($dir),
            escapeshellarg($serverScript),
            $port,
            escapeshellarg($logFile)
        );

        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) {
            return ['ok' => false, 'error' => 'Failed to start static file server.'];
        }

        return ['ok' => true, 'pid' => $pid];
    }

    /**
     * Inline static file server script.
     */
    private static function staticServerScript(): string
    {
        return <<<'SCRIPT'
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { join, extname } from 'node:path';

const PORT = parseInt(process.argv[2] || '51800', 10);
const ROOT = process.cwd();

const MIME = {
  '.html': 'text/html', '.css': 'text/css', '.js': 'application/javascript',
  '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.woff2': 'font/woff2',
  '.woff': 'font/woff', '.ttf': 'font/ttf', '.txt': 'text/plain',
  '.md': 'text/markdown', '.map': 'application/json',
};

const server = createServer(async (req, res) => {
  let url = req.url.split('?')[0];
  if (url.endsWith('/')) url += 'index.html';

  const filePath = join(ROOT, url);
  // Path traversal guard
  if (!filePath.startsWith(ROOT)) {
    res.writeHead(403); res.end('Forbidden'); return;
  }

  try {
    const data = await readFile(filePath);
    const ext = extname(filePath);
    res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
    res.end(data);
  } catch {
    // SPA fallback: serve index.html for non-file paths
    try {
      const data = await readFile(join(ROOT, 'index.html'));
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(data);
    } catch {
      res.writeHead(404); res.end('Not found');
    }
  }
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`Static preview on http://0.0.0.0:${PORT}`);
});
SCRIPT;
    }

    /**
     * Kill a preview process and clean up its files.
     */
    private static function cleanup(array $state): void
    {
        $pid = (int) ($state['pid'] ?? 0);
        if ($pid > 0 && self::isAlive($pid)) {
            // Kill the process tree (Vite spawns children).
            posix_kill($pid, SIGTERM);
            usleep(200_000); // 200ms grace
            if (self::isAlive($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }

        // Clean up temp directory.
        $dir = self::projectDir($state['user_id'], $state['project_id']);
        if (is_dir($dir)) {
            self::rrmdir($dir);
        }
    }

    /**
     * Recursively remove a directory.
     */
    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }

    /**
     * Check if a process is alive.
     */
    private static function isAlive(int $pid): bool
    {
        if ($pid <= 0) return false;
        return posix_kill($pid, 0) || posix_getpgid($pid) !== false;
    }

    /**
     * Wait for a port to accept connections.
     */
    private static function waitForReady(int $port, int $timeoutSec): bool
    {
        $start = time();
        while (time() - $start < $timeoutSec) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if ($fp) {
                fclose($fp);
                return true;
            }
            usleep(500_000); // 500ms
        }
        return false;
    }

    /**
     * Pick a random available port from the range.
     */
    private static function pickPort(): int
    {
        $states = self::loadAllStates();
        $used   = array_column($states, 'port');
        $used   = array_map('intval', $used);

        $min = self::PORT_RANGE[0];
        $max = self::PORT_RANGE[1];
        $candidates = range($min, $max);
        shuffle($candidates);

        foreach ($candidates as $port) {
            if (in_array($port, $used, true)) continue;
            // Also check if port is in use on the system.
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if ($fp) { fclose($fp); continue; }
            return $port;
        }
        return 0;
    }

    // ── State persistence ───────────────────────────────────────────

    private static function loadAllStates(): array
    {
        if (!is_file(self::STATE_FILE)) return [];
        $raw = @file_get_contents(self::STATE_FILE);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function loadState(string $key): ?array
    {
        $states = self::loadAllStates();
        return $states[$key] ?? null;
    }

    private static function saveState(string $key, array $state): void
    {
        $states = self::loadAllStates();
        $states[$key] = $state;

        $dir = dirname(self::STATE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            self::STATE_FILE,
            json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private static function deleteState(string $key): void
    {
        $states = self::loadAllStates();
        unset($states[$key]);
        file_put_contents(
            self::STATE_FILE,
            json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
