<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Core\Database;

final class HostingController
{
    public function index(RequestContext $ctx): void
    {
        $userId = $ctx->user()['id'] ?? null;
        if (!$userId) { $ctx->redirect('/login/'); return; }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM hosting_accounts WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        // Decrypt credentials for display (only the owner sees them).
        foreach ($accounts as &$a) {
            if (!empty($a['db_password']))     { $a['db_password'] = decryptHostingPassword($a['db_password']); }
            if (!empty($a['ftp_password']))    { $a['ftp_password'] = decryptHostingPassword($a['ftp_password']); }
        }
        unset($a);
        $stmt = $pdo->prepare('SELECT COUNT(*) as file_count, SUM(LENGTH(content)) as total_bytes FROM files WHERE user_id = ?');
        $stmt->execute([$userId]);
        $projectStats = $stmt->fetch(\PDO::FETCH_ASSOC);
        $ctx->view('pages/hosting/index', [
            'title' => 'Hosting · ' . APP_NAME,
            'accounts' => $accounts,
            'projectStats' => $projectStats,
        ]);
    }

    public function submit(RequestContext $ctx): void
    {
        $userId = $ctx->user()['id'] ?? null;
        if (!$userId) { $ctx->redirect('/login/'); return; }

        // Check if user has verified email
        if (empty($ctx->user()['email_verified_at'])) {
            $ctx->flash('error', 'Please verify your email address before applying for hosting.');
            $ctx->redirect('/hosting/');
            return;
        }

        $domain = strtolower(trim((string) $ctx->input('domain', '')));
        if (empty($domain)) {
            $ctx->flash('error', 'Please enter a domain name.');
            $ctx->redirect('/hosting/');
            return;
        }

        $pdo = Database::connection();

        // Check if domain already exists
        $stmt = $pdo->prepare('SELECT id FROM hosting_accounts WHERE domain = ?');
        $stmt->execute([$domain]);
        if ($stmt->fetch()) {
            $ctx->flash('error', 'This domain is already registered.');
            $ctx->redirect('/hosting/');
            return;
        }

        // Check if user already has a hosting account (any status except deleted/denied)
        $stmt = $pdo->prepare('SELECT id FROM hosting_accounts WHERE user_id = ? AND status NOT IN (?, ?)');
        $stmt->execute([$userId, 'deleted', 'denied']);
        if ($stmt->fetch()) {
            $ctx->flash('error', 'You already have a hosting account.');
            $ctx->redirect('/hosting/');
            return;
        }

        // Create hosting account
        $stmt = $pdo->prepare('INSERT INTO hosting_accounts (user_id, domain, status, storage_limit) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $domain, 'pending', 150]);

        $ctx->flash('success', 'Your hosting application has been submitted. An admin will review it shortly.');
        $ctx->redirect('/hosting/');
    }

    /** Pause the user's own account (ownership checked). */
    public function pause(RequestContext $ctx, string $id): void
    {
        $this->setStatus($ctx, $id, 'paused', 'Hosting paused. Your site is offline until you resume it.');
    }

    /** Resume the user's own account (ownership checked). */
    public function resume(RequestContext $ctx, string $id): void
    {
        $this->setStatus($ctx, $id, 'active', 'Hosting resumed.');
    }

    /** Delete the user's own account: deprovision (site, DB, FTP) + remove row. */
    public function delete(RequestContext $ctx, string $id): void
    {
        $userId = $ctx->user()['id'] ?? null;
        if (!$userId) { $ctx->redirect('/login/'); return; }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT domain, db_name, db_user FROM hosting_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) $id, $userId]);
        $account = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$account) {
            $ctx->flash('error', 'Account not found.');
            $ctx->redirect('/hosting/');
            return;
        }

        $username = $ctx->user()['username'] ?? '';
        $projectDir = '/home/opc/AshatPlatform/modules/AshatHub/projects/' . $username;
        $script = '/opt/ashat-hub/bin/deprovision-hosting.sh';
        $cmd = "sudo {$script} " . escapeshellarg($account['domain']) . ' ' . escapeshellarg($projectDir) . ' ' . (int) $id
            . ' ' . escapeshellarg((string) $account['db_name']) . ' ' . escapeshellarg((string) $account['db_user']) . " 2>&1";
        shell_exec($cmd);

        $del = $pdo->prepare('DELETE FROM hosting_accounts WHERE id = ? AND user_id = ?');
        $del->execute([(int) $id, $userId]);
        $ctx->flash('success', 'Hosting deleted. Your project files remain in your workspace.');
        $ctx->redirect('/hosting/');
    }

    private function setStatus(RequestContext $ctx, string $id, string $status, string $message): void
    {
        $userId = $ctx->user()['id'] ?? null;
        if (!$userId) { $ctx->redirect('/login/'); return; }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE hosting_accounts SET status = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$status, (int) $id, $userId]);
        $ctx->flash('success', $message);
        $ctx->redirect('/hosting/');
    }
}
