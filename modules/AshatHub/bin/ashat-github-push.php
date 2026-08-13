#!/usr/bin/env php
<?php
declare(strict_types=1);

$repoRoot = '/home/opc/AshatPlatform';
$configPath = $repoRoot . '/modules/AshatHub/config/server_config.json';

function fail(string $msg, int $code = 1): never {
    fwrite(STDERR, "\n✖ {$msg}\n");
    exit($code);
}

function note(string $msg): void {
    fwrite(STDERR, $msg . "\n");
}

function loadConfig(string $path): array {
    if (!is_file($path)) return [];
    $json = json_decode((string) file_get_contents($path), true);
    return is_array($json) ? $json : [];
}

function isProtected(string $path): bool {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $segments = explode('/', $path);
    $basename = end($segments) ?: '';
    foreach (['storage','projects','node_modules','vendor','dist','build','target','models','.git'] as $segment) {
        if (in_array($segment, $segments, true)) return true;
    }
    if ($basename === 'server_config.json' || $basename === '.env' || str_starts_with($basename, '.env.')) return true;
    if (preg_match('/\.(sqlite3?|db|log|pem|key|crt|csr|p12)$/i', $basename) === 1) return true;
    return false;
}

function walk(string $root, string $rel = ''): array {
    $dir = $rel === '' ? $root : $root . '/' . $rel;
    $items = @scandir($dir);
    if ($items === false) return [];
    $files = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $next = $rel === '' ? $item : $rel . '/' . $item;
        $abs = $root . '/' . $next;
        if (isProtected($next)) continue;
        if (is_dir($abs)) {
            $files = array_merge($files, walk($root, $next));
        } elseif (is_file($abs)) {
            $files[] = $next;
        }
    }
    sort($files);
    return $files;
}

function blobSha(string $content): string {
    return sha1('blob ' . strlen($content) . "\0" . $content);
}

function gh(string $url, string $token, string $method = 'GET', ?array $payload = null): array {
    $headers = [
        'User-Agent: ASHAT-Hub-GitHub-Push/1.0',
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
    ];
    $body = $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge($headers, $body ? ['Content-Type: application/json'] : []),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => true,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($raw === false) return ['status' => 0, 'body' => null, 'raw' => ''];
        $respBody = substr($raw, $headerSize);
        return ['status' => $status, 'body' => json_decode($respBody, true), 'raw' => $respBody];
    }

    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", array_merge($headers, $body ? ['Content-Type: application/json'] : [])),
        'content' => $body ?? '',
        'timeout' => 120,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
}

function ghError(array $resp): string {
    $body = $resp['body'] ?? null;
    if (is_array($body) && !empty($body['message'])) return (string) $body['message'];
    return 'GitHub API request failed.';
}

$config = loadConfig($configPath);
$token = trim((string) ($config['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?: ''));
if ($token === '') fail('Missing GITHUB_TOKEN in modules/AshatHub/config/server_config.json');

$message = trim(implode(' ', array_slice($argv, 1)));
if ($message === '') $message = 'Update from ASHAT backend';

$owner = 'buffbot88';
$repo = 'AshatHostingPlatform';
$branch = 'main';

note('Reading remote branch…');
$ref = gh("https://api.github.com/repos/{$owner}/{$repo}/git/ref/heads/{$branch}", $token);
$baseSha = (string) ($ref['body']['object']['sha'] ?? '');
if ($baseSha === '') fail('Could not read remote main: ' . ghError($ref));

note('Reading remote tree…');
$treeResp = gh("https://api.github.com/repos/{$owner}/{$repo}/git/trees/{$baseSha}?recursive=1", $token);
$tree = is_array($treeResp['body']['tree'] ?? null) ? $treeResp['body']['tree'] : null;
if (!is_array($tree)) fail('Could not read remote tree: ' . ghError($treeResp));

$remote = [];
foreach ($tree as $entry) {
    if (($entry['type'] ?? '') !== 'blob') continue;
    $path = (string) ($entry['path'] ?? '');
    if ($path === '' || isProtected($path)) continue;
    $remote[$path] = (string) ($entry['sha'] ?? '');
}

note('Scanning local repo…');
$localFiles = walk($repoRoot);
$treeEntries = [];
$changes = [];
foreach ($localFiles as $path) {
    $abs = $repoRoot . '/' . $path;
    $content = @file_get_contents($abs);
    if ($content === false) continue;
    $sha = blobSha($content);
    if (($remote[$path] ?? '') === $sha) {
        unset($remote[$path]);
        continue;
    }
    $treeEntries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'content' => $content];
    $changes[] = 'M ' . $path;
    unset($remote[$path]);
}
foreach (array_keys($remote) as $path) {
    $treeEntries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
    $changes[] = 'D ' . $path;
}

if ($changes === []) {
    note('No changes to push.');
    exit(0);
}

note('Creating tree…');
$newTree = gh("https://api.github.com/repos/{$owner}/{$repo}/git/trees", $token, 'POST', [
    'base_tree' => $baseSha,
    'tree' => $treeEntries,
]);
$newTreeSha = (string) ($newTree['body']['sha'] ?? '');
if ($newTreeSha === '') fail('Could not create tree: ' . ghError($newTree));

note('Creating commit…');
$commit = gh("https://api.github.com/repos/{$owner}/{$repo}/git/commits", $token, 'POST', [
    'message' => $message,
    'tree' => $newTreeSha,
    'parents' => [$baseSha],
]);
$commitSha = (string) ($commit['body']['sha'] ?? '');
if ($commitSha === '') fail('Could not create commit: ' . ghError($commit));

note('Updating main…');
$update = gh("https://api.github.com/repos/{$owner}/{$repo}/git/refs/heads/{$branch}", $token, 'PATCH', [
    'sha' => $commitSha,
    'force' => true,
]);
if (($update['status'] ?? 0) < 200 || ($update['status'] ?? 0) >= 300) fail('Could not update main: ' . ghError($update));

echo json_encode([
    'ok' => true,
    'summary' => 'Pushed ' . count($changes) . ' change(s) to GitHub main.',
    'commit_sha' => $commitSha,
    'commit_url' => "https://github.com/{$owner}/{$repo}/commit/{$commitSha}",
    'changes' => $changes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
