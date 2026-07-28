<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\GitUpdater — update project from GitHub via git pull or API.
 *
 * Two modes:
 *   1. pull()       — git pull via exec() (requires exec + git installed)
 *   2. incremental() — GitHub API + raw file download (no exec needed)
 *
 * The incremental mode uses the GitHub API to:
 *   - Fetch recent commits
 *   - Compare against a stored "last known commit" in storage/update-state.json
 *   - Download only the files that changed since that commit
 *   - Preserve config files (.env, conn.php, storage/*)
 *
 * Usage:
 *   $updater = new GitUpdater();
 *   $result  = $updater->incremental();  // no exec() needed
 *   $result  = $updater->pull();         // requires exec() + git
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GitUpdater
{
    private string $repoPath;

    /** GitHub repository owner/name. */
    private string $repoOwner = 'buffbot88';
    private string $repoName  = 'AshatHub';
    private string $branch    = 'main';

    /** Files/directories that should NEVER be overwritten during updates. */
    private const PROTECTED_PATHS = [
        '/.env',
        '/config/conn.php',
        '/storage/',
        '/.git/',
    ];

    public function __construct(?string $repoPath = null)
    {
        $this->repoPath = $repoPath ?? (defined('ASHAT_ROOT') ? ASHAT_ROOT : getcwd());
    }

    // ══════════════════════════════════════════════════════════════════
    //  INCREMENTAL UPDATE (GitHub API — no exec() required)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Check what updates are available via the GitHub API.
     * Does NOT download anything — just reports what's new.
     *
     * @return array{ok: bool, behind: int, commits: array, files: array, last_sha: string, latest_sha: string, summary: string, error?: string}
     */
    public function check(): array
    {
        // ── Check HTTP fetch capability ────────────────────────────
        $httpCheck = $this->checkHttpAvailable();
        if (!$httpCheck['ok']) {
            return $httpCheck;
        }

        // ── Fetch recent commits from GitHub API ───────────────────
        $commits = $this->githubGet(
            "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/commits?sha={$this->branch}&per_page=10"
        );

        if ($commits === null) {
            return [
                'ok'      => false,
                'summary' => 'Failed to reach GitHub API.',
                'error'   => 'Could not fetch commits from GitHub API. The server may be blocking outgoing connections.',
            ];
        }

        if (!is_array($commits) || empty($commits)) {
            return [
                'ok'      => false,
                'summary' => 'No commits found.',
                'error'   => 'GitHub API returned an empty or invalid response.',
            ];
        }

        $latestSha = $commits[0]['sha'] ?? '';

        // ── Read stored state ──────────────────────────────────────
        $state = $this->readState();
        $lastSha = $state['last_commit_sha'] ?? '';

        // ── Count new commits ──────────────────────────────────────
        $newCommits = [];
        $changedFiles = [];

        foreach ($commits as $commit) {
            $sha = $commit['sha'] ?? '';
            if ($sha === $lastSha) break; // We've seen this one before

            $newCommits[] = [
                'sha'     => $sha,
                'message' => explode("\n", $commit['commit']['message'] ?? '')[0],
                'date'    => $commit['commit']['committer']['date'] ?? '',
                'author'  => $commit['commit']['author']['name'] ?? '',
            ];

            // Collect changed files for each new commit
            $detail = $this->githubGet($commit['url']);
            if (is_array($detail) && isset($detail['files'])) {
                foreach ($detail['files'] as $file) {
                    $path = $file['filename'] ?? '';
                    if ($path !== '' && !$this->isProtected($path)) {
                        $changedFiles[$path] = [
                            'path'       => $path,
                            'status'     => $file['status'] ?? 'modified',
                            'additions'  => $file['additions'] ?? 0,
                            'deletions'  => $file['deletions'] ?? 0,
                            'raw_url'    => $file['raw_url'] ?? '',
                        ];
                    }
                }
            }
        }

        $behind = count($newCommits);

        return [
            'ok'          => true,
            'behind'      => $behind,
            'commits'     => $newCommits,
            'files'       => array_values($changedFiles),
            'last_sha'    => $lastSha ?: '(first time — will download all files)',
            'latest_sha'  => $latestSha,
            'summary'     => $behind > 0
                ? "{$behind} new commit(s) · " . count($changedFiles) . " file(s) changed"
                : 'Up to date.',
        ];
    }

    /**
     * Apply the incremental update — download only changed files.
     *
     * @return array{ok: bool, output: string, summary: string, files_updated: int, files_created: int, files_deleted: int, error?: string}
     */
    public function incremental(): array
    {
        // ── First, check what's available ──────────────────────────
        $check = $this->check();
        if (!$check['ok']) {
            return [
                'ok'      => false,
                'output'  => '',
                'summary' => $check['summary'] ?? 'Check failed.',
                'error'   => $check['error'] ?? '',
                'files_updated' => 0,
                'files_created' => 0,
                'files_deleted' => 0,
            ];
        }

        if ($check['behind'] === 0) {
            return [
                'ok'      => true,
                'output'  => 'Already up to date.',
                'summary' => 'Already up to date.',
                'files_updated' => 0,
                'files_created' => 0,
                'files_deleted' => 0,
            ];
        }

        $files = $check['files'];
        $updated = 0;
        $created = 0;
        $deleted = 0;
        $log = [];

        foreach ($files as $file) {
            $relativePath = $file['path'];
            $localPath = $this->repoPath . '/' . $relativePath;
            $status = $file['status'];

            try {
                if ($status === 'removed') {
                    // Delete the file locally
                    if (is_file($localPath)) {
                        unlink($localPath);
                        $deleted++;
                        $log[] = "DELETED {$relativePath}";
                    }
                    continue;
                }

                // Ensure parent directory exists
                $dir = dirname($localPath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }

                // Download the file from raw.githubusercontent.com
                $rawUrl = "https://raw.githubusercontent.com/{$this->repoOwner}/{$this->repoName}/{$this->branch}/{$relativePath}";
                $content = $this->httpGet($rawUrl);

                if ($content === null) {
                    $log[] = "FAILED {$relativePath} (download error)";
                    continue;
                }

                if (is_file($localPath)) {
                    $updated++;
                    $log[] = "UPDATED {$relativePath}";
                } else {
                    $created++;
                    $log[] = "CREATED {$relativePath}";
                }

                file_put_contents($localPath, $content);

            } catch (\Throwable $e) {
                $log[] = "ERROR {$relativePath}: {$e->getMessage()}";
            }
        }

        // ── Store the latest commit SHA for next time ──────────────
        $this->saveState([
            'last_commit_sha' => $check['latest_sha'],
            'last_updated'    => date('c'),
        ]);

        $output = implode("\n", $log);

        return [
            'ok'            => true,
            'output'        => $output,
            'summary'       => "Updated {$updated} file(s), created {$created}, deleted {$deleted}.",
            'files_updated' => $updated,
            'files_created' => $created,
            'files_deleted' => $deleted,
        ];
    }

    /**
     * Check if the server can make HTTP requests (for incremental updates).
     */
    public function canHttp(): bool
    {
        return function_exists('file_get_contents') || function_exists('curl_init');
    }

    // ══════════════════════════════════════════════════════════════════
    //  GIT PULL MODE (requires exec() + git installed)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Run a full git pull sequence: fetch + status check + pull.
     * Requires exec() and git to be installed.
     */
    public function pull(): array
    {
        $execCheck = $this->checkExecAvailable();
        if (!$execCheck['ok']) return $execCheck;

        $gitCheck = $this->checkGitInstalled();
        if (!$gitCheck['ok']) return $gitCheck;

        $repoCheck = $this->checkGitRepo();
        if (!$repoCheck['ok']) return $repoCheck;

        $fetch = $this->runCommand('git fetch --prune 2>&1');
        if (!$fetch['ok']) {
            return [
                'ok'      => false, 'output' => $fetch['output'],
                'summary' => 'Git fetch failed.', 'error' => $this->parseFetchError($fetch['output']),
            ];
        }

        $behind = $this->countBehind();
        $dirty = $this->hasLocalChanges();

        $pull = $this->runCommand('git pull --ff-only 2>&1');
        if (!$pull['ok']) {
            $hasConflicts = str_contains($pull['output'], 'conflict')
                         || str_contains($pull['output'], 'Not possible to fast-forward');
            return [
                'ok' => false, 'output' => $pull['output'],
                'summary' => $hasConflicts ? 'Pull blocked: merge required.' : 'Git pull failed.',
                'error' => $this->parsePullError($pull['output']),
                'dirty' => $dirty, 'behind' => $behind, 'conflicts' => $hasConflicts,
            ];
        }

        $commit = $this->runCommand('git log --oneline -1 2>&1');
        $branch = $this->runCommand('git rev-parse --abbrev-ref HEAD 2>&1');

        return [
            'ok' => true, 'output' => trim($pull['output']),
            'summary' => $behind > 0 ? "Updated from GitHub: pulled {$behind} new commit(s)." : 'Already up to date.',
            'commit' => trim($commit['output'] ?? ''), 'branch' => trim($branch['output'] ?? ''),
            'behind' => $behind, 'dirty' => $dirty,
        ];
    }

    /**
     * Quick git status check (requires exec + git).
     */
    public function status(): array
    {
        $execCheck = $this->checkExecAvailable();
        if (!$execCheck['ok']) return $execCheck;
        $gitCheck = $this->checkGitInstalled();
        if (!$gitCheck['ok']) return $gitCheck;
        $repoCheck = $this->checkGitRepo();
        if (!$repoCheck['ok']) return $repoCheck;

        $branch = $this->runCommand('git rev-parse --abbrev-ref HEAD 2>&1');
        $commit = $this->runCommand('git log --oneline -1 2>&1');
        $dirty  = $this->hasLocalChanges();

        return [
            'ok' => true, 'branch' => trim($branch['output'] ?? ''),
            'commit' => trim($commit['output'] ?? ''), 'dirty' => $dirty,
            'summary' => trim($branch['output'] ?? '') . ' @ ' . trim($commit['output'] ?? ''),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE: GitHub API + HTTP helpers
    // ══════════════════════════════════════════════════════════════════

    /**
     * Check if file_get_contents (with allow_url_fopen) or curl is available.
     */
    private function checkHttpAvailable(): array
    {
        if (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            return ['ok' => true];
        }
        if (function_exists('curl_init')) {
            return ['ok' => true];
        }
        return [
            'ok'      => false,
            'summary' => 'HTTP downloads are not available on this server.',
            'error'   => 'Neither allow_url_fopen nor cURL are enabled. Contact your hosting provider to enable one of them.',
        ];
    }

    /**
     * Make a GET request to the GitHub API and parse JSON.
     */
    private function githubGet(string $url): mixed
    {
        $headers = [
            'User-Agent: ASHAT-Hub-Updater/1.0',
            'Accept: application/vnd.github.v3+json',
        ];

        if (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => 15,
                ],
            ]);
            $result = @file_get_contents($url, false, $context);
            if ($result === false) return null;
            return json_decode($result, true);
        }

        if (function_exists('curl_init')) {
            $ch = @curl_init($url);
            if ($ch === false) return null;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $result = @curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($result === false || $httpCode !== 200) return null;
            return json_decode($result, true);
        }

        return null;
    }

    /**
     * Download raw file content from a URL.
     */
    private function httpGet(string $url): ?string
    {
        $headers = ['User-Agent: ASHAT-Hub-Updater/1.0'];

        if (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => 30,
                ],
            ]);
            $result = @file_get_contents($url, false, $context);
            return $result !== false ? $result : null;
        }

        if (function_exists('curl_init')) {
            $ch = @curl_init($url);
            if ($ch === false) return null;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $result = @curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($result !== false && $httpCode === 200) ? $result : null;
        }

        return null;
    }

    /**
     * Check if a file path is protected (should never be overwritten).
     */
    private function isProtected(string $path): bool
    {
        foreach (self::PROTECTED_PATHS as $protected) {
            if (str_starts_with($path, ltrim($protected, '/'))) {
                return true;
            }
        }
        return false;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE: State persistence
    // ══════════════════════════════════════════════════════════════════

    /**
     * Read the update state file (tracks last commit SHA).
     */
    private function readState(): array
    {
        $file = $this->repoPath . '/storage/update-state.json';
        if (!is_file($file)) {
            return ['last_commit_sha' => '', 'last_updated' => ''];
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : ['last_commit_sha' => '', 'last_updated' => ''];
    }

    /**
     * Save the update state to a JSON file.
     */
    private function saveState(array $state): void
    {
        $dir = $this->repoPath . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . '/update-state.json',
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE: Git exec helpers (kept from original)
    // ══════════════════════════════════════════════════════════════════

    private function checkExecAvailable(): array
    {
        if (!function_exists('exec')) {
            return ['ok' => false, 'output' => '', 'summary' => 'exec() is disabled.', 'error' => 'PHP exec() is disabled.'];
        }
        $disabled = explode(',', ini_get('disable_functions') ?: '');
        if (in_array('exec', array_map('trim', $disabled), true)) {
            return ['ok' => false, 'output' => '', 'summary' => 'exec() is disabled.', 'error' => 'exec() disabled via php.ini.'];
        }
        return ['ok' => true];
    }

    private function checkGitInstalled(): array
    {
        $result = $this->runCommand('git --version 2>&1');
        if (!$result['ok'] || !str_contains($result['output'], 'git version')) {
            return ['ok' => false, 'output' => $result['output'] ?? '', 'summary' => 'Git not installed.', 'error' => 'Git CLI not found.'];
        }
        return ['ok' => true];
    }

    private function checkGitRepo(): array
    {
        $result = $this->runCommand('git rev-parse --git-dir 2>&1');
        if (!$result['ok']) {
            return ['ok' => false, 'output' => $result['output'] ?? '', 'summary' => 'Not a git repository.', 'error' => 'No .git directory found.'];
        }
        return ['ok' => true];
    }

    private function countBehind(): int
    {
        $result = $this->runCommand('git rev-list --count HEAD..@{u} 2>&1');
        return $result['ok'] ? (int) trim($result['output']) : 0;
    }

    private function hasLocalChanges(): bool
    {
        $result = $this->runCommand('git status --porcelain 2>&1');
        return $result['ok'] && trim($result['output']) !== '';
    }

    private function runCommand(string $command): array
    {
        $output = [];
        $exitCode = -1;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $this->repoPath, ['GIT_TERMINAL_PROMPT' => '0']);

        if (!is_resource($process)) {
            exec($command . ' 2>&1', $output, $exitCode);
            return ['ok' => $exitCode === 0, 'output' => implode("\n", $output), 'exitCode' => $exitCode];
        }

        fclose($pipes[0]);
        $stdout = '';
        $stderr = '';
        $timeout = 30;
        $start = time();

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            if (time() - $start > $timeout) {
                @proc_terminate($process, 9);
                fclose($pipes[1]); fclose($pipes[2]); @proc_close($process);
                return ['ok' => false, 'output' => $stdout . $stderr . "\n[Timeout after {$timeout}s]", 'exitCode' => -1];
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 1) === false) break;

            $done = true;
            foreach ($read as $r) {
                $chunk = stream_get_contents($r);
                if ($chunk !== false && $chunk !== '') {
                    if ($r === $pipes[1]) $stdout .= $chunk;
                    else $stderr .= $chunk;
                    $done = false;
                }
            }

            $status = @proc_get_status($process);
            if ($status !== false && !$status['running']) {
                $exitCode = $status['exitcode'];
                $remainingStdout = stream_get_contents($pipes[1]);
                $remainingStderr = stream_get_contents($pipes[2]);
                if ($remainingStdout !== false) $stdout .= $remainingStdout;
                if ($remainingStderr !== false) $stderr .= $remainingStderr;
                break;
            }

            if ($done) usleep(100000);
        }

        fclose($pipes[1]); fclose($pipes[2]); @proc_close($process);
        $allOutput = $stdout . ($stderr ? "\n" . $stderr : '');

        return ['ok' => $exitCode === 0, 'output' => trim($allOutput), 'exitCode' => $exitCode];
    }

    private function parseFetchError(string $output): string
    {
        if (str_contains($output, 'could not resolve host')) return 'Could not resolve GitHub hostname.';
        if (str_contains($output, 'Authentication failed')) return 'GitHub authentication failed.';
        if (str_contains($output, 'permission denied')) return 'Permission denied.';
        if (str_contains($output, 'repository not found')) return 'Repository not found.';
        if (str_contains($output, 'timeout')) return 'Connection timed out.';
        return 'Fetch error: ' . mb_substr($output, 0, 300);
    }

    private function parsePullError(string $output): string
    {
        if (str_contains($output, 'conflict')) return 'Merge conflict detected.';
        if (str_contains($output, 'Not possible to fast-forward')) return 'Fast-forward not possible.';
        if (str_contains($output, 'local changes')) return 'Uncommitted local changes.';
        return 'Pull error: ' . mb_substr($output, 0, 300);
    }
}
