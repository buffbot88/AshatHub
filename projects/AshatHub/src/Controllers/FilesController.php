<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * Controllers\FilesController — CRUD for project files.
 * Files are stored on the filesystem AND synced to the database.
 */
final class FilesController
{
    private const QUOTA_BYTES = 150 * 1024 * 1024;
    private const PROJECTS_BASE = ASHAT_ROOT . "/../projects";

    private function userProjectDir(string $userId): string
    {
        $pdo = \Core\Database::connection();
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $username = $stmt->fetchColumn();
        return self::PROJECTS_BASE . "/" . $username;
    }

    private function writeFilesystem(string $userId, string $path, string $content): void
    {
        $dir = $this->userProjectDir($userId);
        $fullPath = $dir . "/" . $path;
        $parentDir = dirname($fullPath);
        if (!is_dir($parentDir)) { mkdir($parentDir, 0755, true); }
        file_put_contents($fullPath, $content);
    }

    private function deleteFilesystem(string $userId, string $path): void
    {
        $fullPath = $this->userProjectDir($userId) . "/" . $path;
        if (is_file($fullPath)) { unlink($fullPath); }
    }

    private function renameFilesystem(string $userId, string $oldPath, string $newPath): void
    {
        $dir = $this->userProjectDir($userId);
        $old = $dir . "/" . $oldPath;
        $new = $dir . "/" . $newPath;
        $newDir = dirname($new);
        if (!is_dir($newDir)) { mkdir($newDir, 0755, true); }
        if (is_file($old)) { rename($old, $new); }
    }

    public function list(RequestContext $ctx): void
    {
        $userId = (string) $ctx->user()["id"];
        $files  = RepositoryRegistry::file()->allForUser($userId);
        $ctx->jsonResponse([
            "files"       => $files,
            "usage_bytes" => RepositoryRegistry::file()->totalBytes($userId),
            "quota_bytes" => self::QUOTA_BYTES,
        ]);
    }

    public function show(RequestContext $ctx, string $id): void
    {
        $file = RepositoryRegistry::file()->find($id, (string) $ctx->user()["id"]);
        if (!$file) $ctx->jsonResponse(["error" => "not_found"], 404);
        $ctx->jsonResponse(["file" => $file]);
    }

    public function readByPath(RequestContext $ctx): void
    {
        $raw = trim((string) ($ctx->query("path") ?? ""));
        if ($raw === "") $ctx->jsonResponse(["error" => "path_required"], 400);
        $path = $this->normalizePath($raw);
        if ($path === "") $ctx->jsonResponse(["error" => "not_found"], 404);
        $file = RepositoryRegistry::file()->findByPath((string) $ctx->user()["id"], $path);
        if (!$file) $ctx->jsonResponse(["error" => "not_found"], 404);
        $ctx->jsonResponse(["file" => $file]);
    }

    public function save(RequestContext $ctx): void
    {
        $body    = $ctx->jsonBody();
        $path    = trim((string) ($body["path"] ?? $ctx->str("path")));
        $content = (string) ($body["content"] ?? $ctx->str("content"));
        if ($path === "") $ctx->jsonResponse(["error" => "path_required"], 400);
        $userId = (string) $ctx->user()["id"];
        $repo   = RepositoryRegistry::file();
        $existing = $repo->findByPath($userId, $path);
        $oldLen   = $existing ? strlen((string) ($existing["content"] ?? "")) : 0;
        $newLen   = strlen($content);
        $usage    = $repo->totalBytes($userId);
        if ($usage - $oldLen + $newLen > self::QUOTA_BYTES) {
            $ctx->jsonResponse(["error" => "quota_exceeded", "usage_bytes" => $usage - $oldLen + $newLen, "quota_bytes" => self::QUOTA_BYTES], 413);
        }
        if (!str_ends_with($path, "/")) { $this->writeFilesystem($userId, $path, $content); }
        $language = \Core\LanguageDetector::detect($path);
        $id       = $repo->save($userId, $path, $content, $language);
        $this->syncToHosting($userId);
        $ctx->jsonResponse(["file" => $repo->find($id, $userId)]);
    }

    public function delete(RequestContext $ctx, string $id): void
    {
        $userId = (string) $ctx->user()["id"];
        $file = RepositoryRegistry::file()->find($id, $userId);
        if ($file && !str_ends_with((string) ($file["path"] ?? ""), "/")) { $this->deleteFilesystem($userId, (string) $file["path"]); }
        RepositoryRegistry::file()->delete($id, $userId);
        $this->syncToHosting($userId);
        $ctx->jsonResponse(["deleted" => $id]);
    }

    public function deleteTree(RequestContext $ctx): void
    {
        $path = trim((string) ($ctx->json("path") ?? $ctx->query("path") ?? ""));
        if ($path === "") $ctx->jsonResponse(["error" => "path_required"], 400);
        $userId = (string) $ctx->user()["id"];
        $dir = $this->userProjectDir($userId) . "/" . $path;
        if (is_dir($dir)) { shell_exec("rm -rf " . escapeshellarg($dir)); }
        $count = RepositoryRegistry::file()->deleteByPrefix($userId, $path);
        $this->syncToHosting($userId);
        $ctx->jsonResponse(["deleted" => $count, "path" => trim($path, "/")]);
    }

    public function createFolder(RequestContext $ctx): void
    {
        $path = $this->normalizePath((string) ($ctx->json("path") ?? $ctx->query("path") ?? ""));
        if ($path === "") $ctx->jsonResponse(["error" => "path_required"], 400);
        $userId = (string) $ctx->user()["id"];
        $marker = $path . "/";
        if (RepositoryRegistry::file()->findByPath($userId, $marker)) {
            $ctx->jsonResponse(["folder" => $marker, "exists" => true]);
            return;
        }
        $dir = $this->userProjectDir($userId) . "/" . $path;
        if (!is_dir($dir)) { mkdir($dir, 0755, true); }
        RepositoryRegistry::file()->save($userId, $marker, "", "", false);
        $ctx->jsonResponse(["folder" => $marker]);
    }

    public function duplicate(RequestContext $ctx): void
    {
        $path = $this->normalizePath((string) ($ctx->json("path") ?? $ctx->query("path") ?? ""));
        if ($path === "") $ctx->jsonResponse(["error" => "path_required"], 400);
        $result = RepositoryRegistry::file()->duplicate((string) $ctx->user()["id"], $path);
        if (($result["error"] ?? "") === "not_found") $ctx->jsonResponse(["error" => "not_found", "path" => $path], 404);
        if (isset($result["path"]) && !str_ends_with($path, "/")) {
            $this->writeFilesystem((string) $ctx->user()["id"], $result["path"], $result["content"] ?? "");
        }
        $ctx->jsonResponse($result);
    }

    public function rename(RequestContext $ctx): void
    {
        $body    = $ctx->jsonBody();
        $oldPath = $this->normalizePath((string) ($body["path"] ?? $ctx->query("path") ?? ""));
        $newPath = $this->normalizePath((string) ($body["newPath"] ?? $body["new_path"] ?? $ctx->query("newPath") ?? ""));
        if ($oldPath === "" || $newPath === "") $ctx->jsonResponse(["error" => "path_required"], 400);
        if ($oldPath === $newPath) $ctx->jsonResponse(["renamed" => 0, "same" => true]);
        if (str_starts_with($newPath, $oldPath . "/")) $ctx->jsonResponse(["error" => "nested_move"], 400);
        $userId = (string) $ctx->user()["id"];
        $this->renameFilesystem($userId, $oldPath, $newPath);
        $result = RepositoryRegistry::file()->rename($userId, $oldPath, $newPath);
        if (($result["error"] ?? "") === "not_found") $ctx->jsonResponse(["error" => "not_found", "path" => $oldPath], 404);
        if (($result["error"] ?? "") === "conflict") $ctx->jsonResponse(["error" => "conflict", "paths" => $result["paths"] ?? []], 409);
        $ctx->jsonResponse($result);
    }

    public function importZip(RequestContext $ctx): void
    {
        $file = $ctx->file("zip");
        if (!$file || ($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file["tmp_name"])) {
            $ctx->jsonResponse(["error" => "zip_required"], 400);
        }
        $raw = file_get_contents((string) $file["tmp_name"]);
        if ($raw === false || $raw === "") $ctx->jsonResponse(["error" => "zip_empty"], 400);
        $entries = \Core\ZipHelper::extract($raw);
        if (!$entries) $ctx->jsonResponse(["error" => "zip_invalid"], 400);
        $userId = (string) $ctx->user()["id"];
        $repo   = RepositoryRegistry::file();
        $usage  = $repo->totalBytes($userId);
        $imported = [];
        $skipped  = 0;
        $addBytes = 0;
        foreach ($entries as $entry) {
            $path = $this->normalizePath($entry["path"]);
            if ($path === "" || str_ends_with($path, "/")) { $skipped++; continue; }
            $existing = $repo->findByPath($userId, $path);
            $oldLen   = $existing ? strlen((string) ($existing["content"] ?? "")) : 0;
            $addBytes += strlen((string) $entry["content"]) - $oldLen;
            $imported[] = ["path" => $path, "content" => (string) $entry["content"]];
        }
        if ($usage + $addBytes > self::QUOTA_BYTES) {
            $ctx->jsonResponse(["error" => "quota_exceeded", "usage_bytes" => $usage + $addBytes, "quota_bytes" => self::QUOTA_BYTES], 413);
        }
        foreach ($imported as $item) {
            $this->writeFilesystem($userId, $item["path"], $item["content"]);
            $language = \Core\LanguageDetector::detect($item["path"]);
            $repo->save($userId, $item["path"], $item["content"], $language);
        }
        $ctx->jsonResponse(["imported" => count($imported), "skipped" => $skipped, "usage_bytes" => $repo->totalBytes($userId), "quota_bytes" => self::QUOTA_BYTES]);
    }

    public function exportZip(RequestContext $ctx): void
    {
        $userId = (string) $ctx->user()["id"];
        $repo   = RepositoryRegistry::file();
        $entries = [];
        foreach ($repo->allForUser($userId) as $meta) {
            $path = (string) $meta["path"];
            if ($path === "" || str_ends_with($path, "/")) continue;
            $row = $repo->find((string) $meta["id"], $userId);
            $entries[] = ["path" => $path, "content" => (string) ($row["content"] ?? "")];
        }
        if (!$entries) $ctx->jsonResponse(["error" => "no_files"], 404);
        $zip      = \Core\ZipHelper::create($entries);
        $filename = "project-" . date("Y-m-d-His") . ".zip";
        $ctx->binaryResponse($zip, $filename, "application/zip");
    }

    private function syncToHosting(string $userId): void
    {
        try {
            $pdo = \Core\Database::connection();
            $stmt = $pdo->prepare("SELECT domain FROM hosting_accounts WHERE user_id = ? AND status = ?");
            $stmt->execute([$userId, "active"]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$account) return;
            $domain = $account["domain"];
            $script = "/opt/ashat-hub/bin/sync-hosting-files.sh";
            $cmd = "sudo {$script} " . escapeshellarg($domain) . " " . escapeshellarg($userId) . " 2>&1";
            shell_exec($cmd);
        } catch (\Throwable $e) {
            error_log("Hosting sync error: " . $e->getMessage());
        }
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace("\\", "/", trim($path));
        $path = trim($path, "/");
        $path = preg_replace('#/{2,}#', "/", $path) ?? "";
        if ($path === "" || $path === "." || $path === "..") return "";
        foreach (explode("/", $path) as $segment) {
            if ($segment === "" || $segment === "." || $segment === ".." || str_contains($segment, ":")) return "";
            if (preg_match('/[\x00-\x1f]/', $segment) === 1) return "";
        }
        return $path;
    }
}
