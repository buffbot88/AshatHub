<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\RepositoryRegistry — service locator for repository swaps.
 *
 * Controllers and services access repositories through the registry:
 *   $users = RepositoryRegistry::user();
 *   $user  = $users->find($id);
 *
 * For tests, swap with in-memory implementations:
 *   $old = RepositoryRegistry::swap('user', new InMemoryUserRepository());
 *   try {
 *       // ... every call to RepositoryRegistry::user() returns the in-memory version
 *   } finally {
 *       RepositoryRegistry::swap('user', $old);
 *   }
 *
 * Each entity type has its own swap target — no more single global
 * Database::swap() that must parse SQL.
 *
 * Production repositories share a single PdoDatabase instance injected
 * through the constructor. Tests can inject a PdoDatabase backed by
 * SQLite via the same constructor parameter.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class RepositoryRegistry
{
    /** @var array<string, object> Overridden instances (swapped for tests). */
    private static array $overrides = [];

    /** @var array<string, object> Cached default instances. */
    private static array $defaults = [];

    // ── Accessors ──────────────────────────────────────────────────

    public static function user(): UserRepository
    {
        /** @var UserRepository */
        return self::resolve('user');
    }

    public static function spec(): SpecRepository
    {
        /** @var SpecRepository */
        return self::resolve('spec');
    }

    public static function file(): FileRepository
    {
        /** @var FileRepository */
        return self::resolve('file');
    }

    public static function build(): BuildRepository
    {
        /** @var BuildRepository */
        return self::resolve('build');
    }

    public static function brainstemConfig(): BrainstemConfigRepository
    {
        /** @var BrainstemConfigRepository */
        return self::resolve('brainstem_config');
    }

    public static function session(): SessionRepository
    {
        /** @var SessionRepository */
        return self::resolve('session');
    }

    public static function communityProject(): CommunityProjectRepository
    {
        /** @var CommunityProjectRepository */
        return self::resolve('community_project');
    }

    public static function docsArticle(): DocsArticleRepository
    {
        /** @var DocsArticleRepository */
        return self::resolve('docs_article');
    }

    // ── Test seam ──────────────────────────────────────────────────

    /**
     * Swap a repository implementation. Returns the old instance so
     * callers can restore it in a finally block.
     */
    public static function swap(string $entity, object $repo): object
    {
        $key = self::normalise($entity);
        $old = self::resolveRaw($key);
        self::$overrides[$key] = $repo;
        return $old;
    }

    /**
     * Restore all repositories to their default implementations.
     * Useful in test setUp/tearDown to guarantee no cross-test leakage.
     */
    public static function reset(): void
    {
        self::$overrides = [];
    }

    // ── Resolvers ──────────────────────────────────────────────────

    /**
     * Resolve a repository — returns the overridden instance if one
     * was set, otherwise instantiates and caches the default.
     */
    private static function resolve(string $key): object
    {
        $key = self::normalise($key);

        if (isset(self::$overrides[$key])) {
            return self::$overrides[$key];
        }

        if (!isset(self::$defaults[$key])) {
            self::$defaults[$key] = self::default($key);
        }

        return self::$defaults[$key];
    }

    /**
     * Resolve the raw instance without caching (used by swap to
     * retrieve the previous instance).
     */
    private static function resolveRaw(string $key): object
    {
        if (isset(self::$overrides[$key])) {
            return self::$overrides[$key];
        }
        return self::default($key);
    }

    private static function normalise(string $key): string
    {
        return strtolower(trim($key));
    }

    private static function default(string $key): object
    {
        static $pdoDb    = null;
        static $config   = null;
        $pdoDb    ??= new PdoDatabase();
        $config   ??= \Core\ConfigBag::getInstance();

        return match ($key) {
            'user'            => new PdoUserRepository($pdoDb),
            'spec'            => new PdoSpecRepository($pdoDb),
            'file'            => new PdoFileRepository($pdoDb),
            'build'           => new PdoBuildRepository($pdoDb),
            'brainstem_config' => new PdoBrainstemConfigRepository($pdoDb, $config),
            'session'           => new PdoSessionRepository($pdoDb),
            'community_project'  => new PdoCommunityProjectRepository($pdoDb),
            'docs_article'       => new PdoDocsArticleRepository($pdoDb),
            default => throw new \InvalidArgumentException("Unknown repository: $key"),
        };
    }
}
