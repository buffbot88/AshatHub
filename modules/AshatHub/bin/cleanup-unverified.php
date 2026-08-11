<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — Purge unverified accounts (data minimization)
 *
 * Deletes accounts whose email was never verified and that were created
 * more than HOURS ago (default 48). No-op when EMAIL_VERIFICATION_ENABLED
 * is off, so it's safe to run on any install.
 *
 * Usage:
 *   php bin/cleanup-unverified.php [HOURS]
 * ═══════════════════════════════════════════════════════════════════════
 */
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

$hours  = isset($argv[1]) ? max(1, (int) $argv[1]) : 48;
$purged = \Core\AuthService::purgeUnverified($hours);

echo "Purged {$purged} unverified account(s) older than {$hours}h.\n";
