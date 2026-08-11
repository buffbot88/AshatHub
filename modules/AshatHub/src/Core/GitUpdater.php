<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\GitUpdater — update project from GitHub via git pull, archive sync, or API.
 *
 * Modes:
 *   1. zipUpdate() — full-repo archive sync (recommended, no exec needed)
 *   2. incremental() — GitHub API + raw file download (legacy)
 *   3. pull()       — git pull via exec() (requires exec + git installed)
 *
 * zipUpdate() downloads the branch archive (main.zip), extracts it with
 * Core\ZipHelper, overwrites changed files, and deletes stale local files.
 *
 * Usage:
 *   $updater = new GitUpdater();
 *   $result  = $updater->zipUpdate();     // recommended
 *   $result  = $updater->incremental();   // legacy API mode
 *   $result  = $updater->pull();          // requires exec() + git
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GitUpdater
{
    private string $repoPath;

    /** GitHub repository owner/name. */
    private string $repoOwner = 'buffbot88';
    private string $repoName  = 'AshatHostingPlatform';
    private string $branch    = 'main';

    /** Remote repository prefix mapped onto the local Ashat Hub module root. */
    private const SOURCE_PREFIX = 'modules/AshatHub/';

    /** Files/directories that should NEVER be overwritten or deleted. */
    private const PROTECTED_PATHS = [
        '/.env',
        '/.env.local',
        '/config/conn.php',
        '/config/server_config.json',
        '/projects/',
        '/vendor/',
        '/dist/',
        '/build/',
        '/target/',
        '/models/',
        '/storage/',
        '/.git/',
        '/node_modules/',
        '/phpunit.phar',
        '/.phpunit.cache/',
        '/.vscode/',
        '/.idea/',
        '/AshatOS_Old/',
        '/.freebuff',
        '/public/css/build/',
        '/public/js/build/',
        '/.DS_Store',
        '/Thumbs.db',
    ];

    /** Alias of PROTECTED_PATHS — cleanup must also never delete these. */
    private const CLEANUP_EXCLUDE = self::PROTECTED_PATHS;

    /** Known repo source dirs — cleanup prunes these even if renamed/absent upstream. */
    private const TRACKED_TOP_DIRS = ['src', 'public', 'config', 'tests', 'db'];

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
     * @return array{ok: bool, behind: int, commits: array, files: array, last_sha: string, latest_sha: string, summary: string, webhook_received_at?: string, webhook_head?: string, error?: string}
     */
    public function check(): array
    {
        // ── Check HTTP fetch capability ────────────────────────────
        $httpCheck = $this->checkHttpAvailable();

        // ── Surface a webhook-recorded push (never auto-applied) ───
        // Read the flag before the HTTP check so a recorded push is
        // surfaced even when the GitHub API is unreachable.
        $pending = $this->readWebhookPush();
        if ($pending !== []) {
            $httpCheck['webhook_received_at'] = $pending['received_at'] ?? '';
            $httpCheck['webhook_head']        = $pending['head_sha'] ?? '';
        }

        if (!$httpCheck['ok']) {
            return $httpCheck;
        }

        // ── Read stored state ──────────────────────────────────────
        $state = $this->readState();
        $lastSha = ($state['repository'] ?? '') === $this->repositoryKey()
            ? (string) ($state['last_commit_sha'] ?? '')
            : '';

        // ── GitHub Compare API: single call for commits + files ────
        // If we have a stored SHA, the compare endpoint returns ALL
        // new commits and ALL changed files in one response — instead
        // of N+1 calls (1 commits list + N commit details).
        if ($lastSha !== '') {
            $compareUrl = "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/compare/{$lastSha}...{$this->branch}";
            $compare = $this->githubGet($compareUrl);

            if ($compare === null) {
                return [
                    'ok'      => false,
                    'summary' => 'Failed to reach GitHub API.',
                    'error'   => 'Could not reach the GitHub Compare API. The server may be blocking outgoing connections.',
                ];
            }

            if (!is_array($compare) || !isset($compare['status'])) {
                return [
                    'ok'      => false,
                    'summary' => 'Invalid response from GitHub.',
                    'error'   => 'GitHub Compare API returned an invalid response.',
                ];
            }

            // 'behind' means local is behind remote (has unpulled commits)
            // 'identical' means up to date
            // 'diverged' means branches have diverged (unlikely with --ff-only)
            $behind = $compare['behind_by'] ?? 0;

            // Build commit list from compare response
            $newCommits = [];
            $rawCommits = $compare['commits'] ?? [];
            foreach ($rawCommits as $commit) {
                $sha = $commit['sha'] ?? '';
                $newCommits[] = [
                    'sha'     => $sha,
                    'message' => explode("\n", $commit['commit']['message'] ?? '')[0],
                    'date'    => $commit['commit']['committer']['date'] ?? '',
                    'author'  => $commit['commit']['author']['name'] ?? '',
                ];
            }

            // Build file list from compare response (single call!)
            $changedFiles = [];
            $rawFiles = $compare['files'] ?? [];
            foreach ($rawFiles as $file) {
                $path = (string) ($file['filename'] ?? '');
                $localPath = $this->remoteToLocalPath($path);
                if ($localPath !== null && !$this->isProtected($localPath)) {
                    $changedFiles[$localPath] = [
                        'path'       => $localPath,
                        'remote_path'=> $path,
                        'status'     => $file['status'] ?? 'modified',
                        'additions'  => $file['additions'] ?? 0,
                        'deletions'  => $file['deletions'] ?? 0,
                        'raw_url'    => $file['raw_url'] ?? '',
                    ];
                }
            }

            // The tip of the branch (latest HEAD SHA) is the last
            // commit in the Compare API's commits array.
            $latestSha = $lastSha;
            if (!empty($rawCommits)) {
                $last = end($rawCommits);
                $latestSha = $last['sha'] ?? $lastSha;
            }

            return [
                'ok'          => true,
                'behind'      => $behind,
                'commits'     => $newCommits,
                'files'       => array_values($changedFiles),
                'last_sha'    => $lastSha,
                'latest_sha'  => $latestSha,
                'summary'     => $behind > 0
                    ? "{$behind} new commit(s) · " . count($changedFiles) . " file(s) changed"
                    : 'Up to date.',
                'webhook_received_at' => $pending['received_at'] ?? '',
                'webhook_head'        => $pending['head_sha'] ?? '',
            ];
        }

        // ── First-time check (no stored SHA yet) ───────────────────
        // Without a known starting point we can't use the Compare API.
        // Just fetch the recent commits list to show what's available.
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

        $newCommits = [];
        foreach ($commits as $commit) {
            $sha = $commit['sha'] ?? '';
            $newCommits[] = [
                'sha'     => $sha,
                'message' => explode("\n", $commit['commit']['message'] ?? '')[0],
                'date'    => $commit['commit']['committer']['date'] ?? '',
                'author'  => $commit['commit']['author']['name'] ?? '',
            ];
        }

        $behind = count($newCommits);

        return [
            'ok'          => true,
            'behind'      => $behind,
            'commits'     => $newCommits,
            'files'       => [],
            'last_sha'    => '(first time — will download all files)',
            'latest_sha'  => $latestSha,
            'summary'     => "{$behind} recent commit(s) — run Apply to start tracking",
            'webhook_received_at' => $pending['received_at'] ?? '',
            'webhook_head'        => $pending['head_sha'] ?? '',
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
            $relativePath = (string) ($file['path'] ?? '');
            $remotePath = (string) ($file['remote_path'] ?? $this->remotePath($relativePath));
            $localPath = $this->repoPath . '/' . $relativePath;
            $status = $file['status'] ?? 'modified';

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
                $encodedRemotePath = implode('/', array_map('rawurlencode', explode('/', $remotePath)));
                $rawUrl = "https://raw.githubusercontent.com/{$this->repoOwner}/{$this->repoName}/{$this->branch}/{$encodedRemotePath}";
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
            'repository'      => $this->repositoryKey(),
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
    //  ZIP ARCHIVE UPDATE (main.zip — no exec() required)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Download the branch archive zip, then sync the local tree to match it.
     * Returns the applyArchive() result plus a best-effort latest commit SHA.
     *
     * @return array{ok: bool, output: string, summary: string, files_updated: int, files_created: int, files_deleted: int, files_unchanged: int, latest_sha: string, error?: string}
     */
    public function zipUpdate(?string $expectedSha = null): array
    {
        // ── Check HTTP fetch capability ────────────────────────────
        $httpCheck = $this->checkHttpAvailable();
        if (!$httpCheck['ok']) {
            return $httpCheck;
        }

        // Resolve the production tip before downloading anything so an Apply
        // request is bound to the exact SHA shown in the admin preview.
        $latestSha = '';
        $tip = $this->githubGet(
            "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/commits/{$this->branch}"
        );
        if (is_array($tip) && !empty($tip['sha'])) {
            $latestSha = (string) $tip['sha'];
        }
        if ($expectedSha !== null && $expectedSha !== '') {
            if ($latestSha === '') {
                return [
                    'ok' => false, 'summary' => 'Could not verify the production revision.',
                    'error' => 'GitHub did not return the current main commit; nothing was applied.',
                    'output' => '', 'files_updated' => 0, 'files_created' => 0,
                    'files_deleted' => 0, 'files_unchanged' => 0, 'latest_sha' => '',
                ];
            }
            if (!hash_equals($expectedSha, $latestSha)) {
                return [
                    'ok' => false, 'summary' => 'Update preview is stale.',
                    'error' => 'main changed after the preview; run Check for Updates again.',
                    'output' => '', 'files_updated' => 0, 'files_created' => 0,
                    'files_deleted' => 0, 'files_unchanged' => 0, 'latest_sha' => $latestSha,
                ];
            }
        }

        if ($latestSha === '') {
            return [
                'ok' => false, 'summary' => 'Could not determine the production revision.',
                'error' => 'GitHub did not return a commit SHA; nothing was applied.',
                'output' => '', 'files_updated' => 0, 'files_created' => 0,
                'files_deleted' => 0, 'files_unchanged' => 0, 'latest_sha' => '',
            ];
        }

        // Download an immutable commit archive only after the expected
        // production SHA is verified; refs/heads/main could move mid-request.
        $zipUrl = "https://github.com/{$this->repoOwner}/{$this->repoName}/archive/{$latestSha}.zip";
        $zip = $this->downloadArchive($zipUrl);
        if ($zip === null || $zip === '') {
            return [
                'ok' => false, 'summary' => 'Failed to download the repository archive.',
                'error' => 'Could not download main.zip from GitHub. The server may be blocking outgoing connections or the archive request timed out.',
                'output' => '', 'files_updated' => 0, 'files_created' => 0,
                'files_deleted' => 0, 'files_unchanged' => 0, 'latest_sha' => $latestSha,
            ];
        }

        // Apply the archive to the local Ashat Hub module tree.
        $result = $this->applyArchive($zip, $latestSha);

        // Only advance the stored commit when the apply actually succeeded,
        // otherwise the next check() would wrongly report "up to date".
        if ($result['ok'] && $latestSha !== '') {
            $this->saveState([
                'last_commit_sha' => $latestSha,
                'last_updated'    => date('c'),
                'repository'      => $this->repositoryKey(),
            ]);
        }

        return $result;
    }

    /**
     * Extract a branch archive zip into the local tree: overwrite changed
     * files, create new ones, and delete files absent from the archive
     * (cleanup pass). Protected/gitignored paths are never touched.
     *
     * @return array{ok: bool, output: string, summary: string, files_updated: int, files_created: int, files_deleted: int, files_unchanged: int, latest_sha: string, error?: string}
     */
    public function applyArchive(string $zipBytes, string $latestSha = ''): array
    {
        // ── Extract entries (ZipHelper verifies CRC, skips dirs) ───
        $entries = ZipHelper::extract($zipBytes);
        if (!$entries) {
            return [
                'ok'      => false,
                'summary' => 'Archive invalid or empty.',
                'error'   => 'The downloaded archive could not be read (empty or corrupt).',
                'output'  => '', 'files_updated' => 0, 'files_created' => 0,
                'files_deleted' => 0, 'files_unchanged' => 0, 'latest_sha' => $latestSha,
            ];
        }

        // ── First pass: collect sanitized paths + track top-level dirs
        $archivePaths = [];
        $topLevelDirs = [];
        foreach ($entries as $entry) {
            $remotePath = $this->archivePath($entry['path']);
            $rel = $this->remoteToLocalPath($remotePath);
            if ($rel === null || $this->isProtected($rel)) continue;
            $archivePaths[$rel] = true;
            $top = str_contains($rel, '/') ? explode('/', $rel, 2)[0] : '';
            if ($top !== '') $topLevelDirs[$top] = true;
        }

        // ── Second pass: write/overwrite files ─────────────────────
        $updated   = 0;
        $created   = 0;
        $unchanged = 0;
        $log       = [];

        foreach ($entries as $entry) {
            $remotePath = $this->archivePath($entry['path']);
            $rel = $this->remoteToLocalPath($remotePath);
            if ($rel === null || $this->isProtected($rel)) continue;

            $localPath = $this->repoPath . '/' . $rel;
            $dir = dirname($localPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $content = (string) $entry['content'];

            if (is_file($localPath)) {
                $existing = @file_get_contents($localPath);
                if ($existing === $content) {
                    $unchanged++;
                    continue;
                }
                $updated++;
                $log[] = "UPDATED {$rel}";
            } else {
                $created++;
                $log[] = "CREATED {$rel}";
            }

            if (@file_put_contents($localPath, $content) === false) {
                // Undo the optimistic count — the write failed.
                if (is_file($localPath)) $updated--;
                else $created--;
                $log[] = "FAILED {$rel} (unwritable)";
            }
        }

        // ── Cleanup pass: delete local files missing from the archive
        $deleted = 0;
        foreach ($this->collectLocalFiles() as $rel) {
            if (isset($archivePaths[$rel])) continue;
            if ($this->isCleanupExcluded($rel) || $this->isProtected($rel)) continue;

            // Prune files under a top-level folder the repo tracks (either
            // present in the archive or a known source dir), so unrelated
            // local dirs (uploads/, backups/) are left alone. Root files
            // absent from the archive are pruned too — .env & friends are
            // already protected by CLEANUP_EXCLUDE.
            $top = str_contains($rel, '/') ? explode('/', $rel, 2)[0] : '';
            if ($top !== '' && !isset($topLevelDirs[$top]) && !in_array($top, self::TRACKED_TOP_DIRS, true)) continue;

            $localPath = $this->repoPath . '/' . $rel;
            if (is_file($localPath) && @unlink($localPath)) {
                $deleted++;
                $log[] = "DELETED {$rel}";
            }
        }

        // A successful manual apply consumes any pending webhook push flag.
        $this->clearWebhookPush();

        return [
            'ok'            => true,
            'output'        => implode("\n", $log),
            'summary'       => "Updated {$updated} file(s), created {$created}, deleted {$deleted}, unchanged {$unchanged}.",
            'files_updated' => $updated,
            'files_created' => $created,
            'files_deleted' => $deleted,
            'files_unchanged' => $unchanged,
            'latest_sha'    => $latestSha,
        ];
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
     * Download the full branch archive (binary zip) with a long timeout.
     */
    private function downloadArchive(string $url): ?string
    {
        $headers = ['User-Agent: ASHAT-Hub-Updater/1.0'];

        if (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => 120,
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
                CURLOPT_TIMEOUT        => 120,
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
     * Convert a raw archive entry name to a safe repo-relative path.
     * Strips the "{repo}-{branch}/" top folder and rejects traversal,
     * drive-letter segments, and control chars (returns '' when unsafe).
     */
    private function archivePath(string $raw): string
    {
        $path = str_replace('\\', '/', trim($raw));
        $path = trim($path, '/');
        $slash = strpos($path, '/');
        $path = $slash === false ? '' : substr($path, $slash + 1);
        $path = trim($path, '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?? '';
        if ($path === '' || $path === '.' || $path === '..') return '';
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) return '';
            if (preg_match('/[\x00-\x1f]/', $segment) === 1) return '';
        }
        return $path;
    }

    /**
     * True when a repo-relative path is gitignored/local-only (cleanup-skip).
     */
    /** Convert a remote repository path to a local Ashat Hub module path. */
    private function remoteToLocalPath(string $remotePath): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim($remotePath)), '/');
        if (!str_starts_with($path, self::SOURCE_PREFIX)) return null;
        $local = substr($path, strlen(self::SOURCE_PREFIX));
        return ($local === '' || str_contains($local, '..')) ? null : $local;
    }

    /** Convert a local module path to its remote repository path. */
    private function remotePath(string $localPath): string
    {
        return self::SOURCE_PREFIX . ltrim(str_replace('\\', '/', $localPath), '/');
    }

    private function repositoryKey(): string
    {
        return $this->repoOwner . '/' . $this->repoName . '@' . $this->branch;
    }

    private function isCleanupExcluded(string $path): bool
    {
        foreach (self::CLEANUP_EXCLUDE as $excluded) {
            if (str_starts_with($path, ltrim($excluded, '/'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively list repo-relative file paths, skipping excluded dirs.
     */
    private function collectLocalFiles(): array
    {
        $files = [];
        $this->walkLocalDir('', $files);
        return $files;
    }

    /**
     * Depth-first walk helper for collectLocalFiles().
     */
    private function walkLocalDir(string $relDir, array &$files): void
    {
        $absDir = $relDir === '' ? $this->repoPath : $this->repoPath . '/' . $relDir;
        $items = @scandir($absDir);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $rel = $relDir === '' ? $item : $relDir . '/' . $item;
            $abs = $absDir . '/' . $item;
            if (is_dir($abs)) {
                if ($this->isCleanupExcluded($rel . '/')) continue;
                $this->walkLocalDir($rel, $files);
            } elseif (is_file($abs)) {
                $files[] = $rel;
            }
        }
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
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $normalized);
        $basename = end($segments) ?: '';
        $protectedSegments = ['storage', 'projects', 'node_modules', 'vendor', 'dist', 'build', 'target', 'models', '.git'];
        foreach ($segments as $segment) {
            if (in_array($segment, $protectedSegments, true)) return true;
        }
        if ($basename === 'server_config.json' || $basename === '.env' || str_starts_with($basename, '.env.')) return true;
        if (preg_match('/\\.(sqlite3?|db|log|pem|key|crt|csr|p12)$/i', $basename) === 1) return true;
        foreach (self::PROTECTED_PATHS as $protected) {
            if (str_starts_with($normalized, ltrim($protected, '/'))) return true;
        }
        return false;
    }

    // ══════════════════════════════════════════════════════════════════
    //  WEBHOOK PUSH FLAG (record-only — never auto-applies)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Record a verified GitHub push so the admin can review before applying.
     * The push is NEVER applied automatically — check() surfaces it instead.
     */
    public function recordWebhookPush(string $headSha = ''): void
    {
        $dir = $this->repoPath . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . '/webhook-push.json',
            json_encode([
                'received_at' => date('c'),
                'head_sha'    => $headSha,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Read the pending webhook push flag, or [] when none is recorded.
     */
    private function readWebhookPush(): array
    {
        $file = $this->repoPath . '/storage/webhook-push.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Clear the pending webhook push flag (called after a successful apply).
     */
    public function clearWebhookPush(): void
    {
        $file = $this->repoPath . '/storage/webhook-push.json';
        if (is_file($file)) {
            @unlink($file);
        }
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
