<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\GitUpdater — safely run git pull from the project root.
 *
 * Handles edge cases: exec() disabled, not a git repo, already up to
 * date, merge conflicts, authentication failures, and long-running pulls.
 *
 * Usage:
 *   $updater = new GitUpdater();
 *   $result  = $updater->pull();  // returns ['ok' => bool, 'output' => string, ...]
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GitUpdater
{
    private string $repoPath;

    /**
     * @param string|null $repoPath Path to the git repo root.
     *        Defaults to ASHAT_ROOT constant (the project root).
     */
    public function __construct(?string $repoPath = null)
    {
        $this->repoPath = $repoPath ?? (defined('ASHAT_ROOT') ? ASHAT_ROOT : getcwd());
    }

    /**
     * Run a full git pull sequence: fetch + status check + pull.
     *
     * @return array{ok: bool, output: string, summary: string, error?: string, dirty?: bool, behind?: int, conflicts?: bool}
     */
    public function pull(): array
    {
        // ── Prerequisite checks ──────────────────────────────────────
        $execCheck = $this->checkExecAvailable();
        if (!$execCheck['ok']) {
            return $execCheck;
        }

        $gitCheck = $this->checkGitInstalled();
        if (!$gitCheck['ok']) {
            return $gitCheck;
        }

        $repoCheck = $this->checkGitRepo();
        if (!$repoCheck['ok']) {
            return $repoCheck;
        }

        // ── 1. git fetch ───────────────────────────────────────────
        $fetch = $this->runCommand('git fetch --prune 2>&1');
        if (!$fetch['ok']) {
            return [
                'ok'     => false,
                'output' => $fetch['output'],
                'summary' => 'Git fetch failed.',
                'error'  => $this->parseFetchError($fetch['output']),
            ];
        }

        // ── 2. Check how many commits we're behind ────────────────
        $behind = $this->countBehind();

        // ── 3. Check for local changes that could cause conflict ──
        $dirty = $this->hasLocalChanges();

        // ── 4. git pull (fast-forward only to avoid merge commits) ─
        //    Using --ff-only fails if there are merge conflicts, which
        //    is what we want — safer than letting git create a merge.
        $pull = $this->runCommand('git pull --ff-only 2>&1');

        if (!$pull['ok']) {
            $hasConflicts = str_contains($pull['output'], 'conflict')
                         || str_contains($pull['output'], 'Not possible to fast-forward');

            $summary = $hasConflicts
                ? 'Pull blocked: merge required.'
                : 'Git pull failed.';

            return [
                'ok'        => false,
                'output'    => $pull['output'],
                'summary'   => $summary,
                'error'     => $this->parsePullError($pull['output']),
                'dirty'     => $dirty,
                'behind'    => $behind,
                'conflicts' => $hasConflicts,
            ];
        }

        // ── 5. Get current commit info ───────────────────────────
        $commit = $this->runCommand('git log --oneline -1 2>&1');
        $branch = $this->runCommand('git rev-parse --abbrev-ref HEAD 2>&1');

        $summary = $behind > 0
            ? "Updated from GitHub: pulled {$behind} new commit(s)."
            : 'Already up to date.';

        return [
            'ok'       => true,
            'output'   => trim($pull['output']),
            'summary'  => $summary,
            'commit'   => trim($commit['output'] ?? ''),
            'branch'   => trim($branch['output'] ?? ''),
            'behind'   => $behind,
            'dirty'    => $dirty,
        ];
    }

    /**
     * Quick check: get the current branch and latest commit (no fetch).
     *
     * @return array{ok: bool, branch?: string, commit?: string, summary?: string, error?: string}
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
            'ok'      => true,
            'branch'  => trim($branch['output'] ?? ''),
            'commit'  => trim($commit['output'] ?? ''),
            'dirty'   => $dirty,
            'summary' => trim($branch['output'] ?? '') . ' @ ' . trim($commit['output'] ?? ''),
        ];
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * Check if exec() is available and not disabled.
     */
    private function checkExecAvailable(): array
    {
        if (!function_exists('exec')) {
            return [
                'ok'      => false,
                'output'  => '',
                'summary' => 'exec() is disabled on this server.',
                'error'   => 'PHP exec() function is required for git operations but is disabled.',
            ];
        }
        $disabled = explode(',', ini_get('disable_functions') ?: '');
        if (in_array('exec', array_map('trim', $disabled), true)) {
            return [
                'ok'      => false,
                'output'  => '',
                'summary' => 'exec() is disabled on this server.',
                'error'   => 'The exec() function has been disabled via disable_functions in php.ini.',
            ];
        }
        return ['ok' => true];
    }

    /**
     * Check if git is installed and accessible.
     */
    private function checkGitInstalled(): array
    {
        $result = $this->runCommand('git --version 2>&1');
        if (!$result['ok'] || !str_contains($result['output'], 'git version')) {
            return [
                'ok'      => false,
                'output'  => $result['output'] ?? '',
                'summary' => 'Git is not installed on this server.',
                'error'   => 'The git CLI was not found. Install git or contact your hosting provider.',
            ];
        }
        return ['ok' => true];
    }

    /**
     * Check if the project directory is a git repository.
     */
    private function checkGitRepo(): array
    {
        $result = $this->runCommand('git rev-parse --git-dir 2>&1');
        if (!$result['ok']) {
            $output = $result['output'] ?? '';
            return [
                'ok'      => false,
                'output'  => $output,
                'summary' => 'Not a git repository.',
                'error'   => 'This project was not cloned from GitHub (no .git directory found). To enable updates, clone the repo instead of uploading files via FTP.',
            ];
        }
        return ['ok' => true];
    }

    /**
     * Count how many commits we're behind the remote tracking branch.
     */
    private function countBehind(): int
    {
        $result = $this->runCommand('git rev-list --count HEAD..@{u} 2>&1');
        if (!$result['ok']) return 0;
        return (int) trim($result['output']);
    }

    /**
     * Check if there are uncommitted local changes.
     */
    private function hasLocalChanges(): bool
    {
        $result = $this->runCommand('git status --porcelain 2>&1');
        if (!$result['ok']) return false;
        return trim($result['output']) !== '';
    }

    /**
     * Run a shell command via exec() with a timeout.
     *
     * @return array{ok: bool, output: string, exitCode: int}
     */
    private function runCommand(string $command): array
    {
        $output   = [];
        $exitCode = -1;

        // Use proc_open for timeout support (safer than exec alone)
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->repoPath,
            ['GIT_TERMINAL_PROMPT' => '0']  // suppress git credential prompts
        );

        if (!is_resource($process)) {
            // Fallback: plain exec if proc_open fails
            exec($command . ' 2>&1', $output, $exitCode);
            return [
                'ok'       => $exitCode === 0,
                'output'   => implode("\n", $output),
                'exitCode' => $exitCode,
            ];
        }

        // Close stdin immediately (no input needed)
        fclose($pipes[0]);

        // Read output with a timeout (30 seconds max for git pull)
        $stdout = '';
        $stderr = '';
        $timeout = 30;
        $start = time();

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            if (time() - $start > $timeout) {
                // Kill the process on timeout
                @proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                @proc_close($process);
                return [
                    'ok'       => false,
                    'output'   => $stdout . $stderr . "\n[Error: Command timed out after {$timeout}s]",
                    'exitCode' => -1,
                ];
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }

            $done = true;
            foreach ($read as $r) {
                $chunk = stream_get_contents($r);
                if ($chunk !== false && $chunk !== '') {
                    if ($r === $pipes[1]) $stdout .= $chunk;
                    else $stderr .= $chunk;
                    $done = false;
                }
            }

            // Check if process is still running
            $status = @proc_get_status($process);
            if ($status !== false && !$status['running']) {
                $exitCode = $status['exitcode'];
                // Read any remaining output
                $remainingStdout = stream_get_contents($pipes[1]);
                $remainingStderr = stream_get_contents($pipes[2]);
                if ($remainingStdout !== false) $stdout .= $remainingStdout;
                if ($remainingStderr !== false) $stderr .= $remainingStderr;
                break;
            }

            if ($done) {
                usleep(100000); // 100ms
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        @proc_close($process);

        $allOutput = $stdout . ($stderr ? "\n" . $stderr : '');

        return [
            'ok'       => $exitCode === 0,
            'output'   => trim($allOutput),
            'exitCode' => $exitCode,
        ];
    }

    /**
     * Try to give a human-readable reason for fetch failures.
     */
    private function parseFetchError(string $output): string
    {
        if (str_contains($output, 'could not resolve host')) {
            return 'Could not resolve GitHub hostname. Check internet/DNS.';
        }
        if (str_contains($output, 'Authentication failed') || str_contains($output, 'could not read Username')) {
            return 'GitHub authentication failed. If using HTTPS, set up a personal access token. If using SSH, check your keys.';
        }
        if (str_contains($output, 'permission denied')) {
            return 'Permission denied. Check your GitHub credentials and repository access.';
        }
        if (str_contains($output, 'repository not found')) {
            return 'Repository not found. Check your remote origin URL.';
        }
        if (str_contains($output, 'timeout') || str_contains($output, 'Operation timed out')) {
            return 'Connection timed out. GitHub may be unreachable from this server.';
        }
        return 'An unknown error occurred during fetch. Output: ' . mb_substr($output, 0, 300);
    }

    /**
     * Try to give a human-readable reason for pull failures.
     */
    private function parsePullError(string $output): string
    {
        if (str_contains($output, 'conflict')) {
            return 'Merge conflict detected. The remote has changes that conflict with your local modifications. Resolve manually via command line or stash your changes first.';
        }
        if (str_contains($output, 'Not possible to fast-forward')) {
            return 'Fast-forward merge is not possible. This usually means local commits exist that are not on the remote. Use `git pull --rebase` manually or reset to the remote branch.';
        }
        if (str_contains($output, 'local changes')) {
            return 'You have uncommitted local changes. Commit or stash them before pulling.';
        }
        return 'An unknown error occurred during pull. Output: ' . mb_substr($output, 0, 300);
    }
}
