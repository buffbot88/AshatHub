<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\RepositoryRegistry — service locator for repository swaps:
 * controllers access repositories via RepositoryRegistry::user() etc.,
 * and tests swap in-memory implementations with swap() (restored in a
 * finally block; each entity has its own swap target). Production
 * repositories share a single PdoDatabase; tests can inject a SQLite one.
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

    public static function file(): FileRepository
    {
        /** @var FileRepository */
        return self::resolve('file');
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

    public static function ticket(): TicketRepository
    {
        /** @var TicketRepository */
        return self::resolve('ticket');
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
            'file'            => new PdoFileRepository($pdoDb),
            'brainstem_config' => new PdoBrainstemConfigRepository($pdoDb, $config),
            'session'           => new PdoSessionRepository($pdoDb),
            'community_project'  => new PdoCommunityProjectRepository($pdoDb),
            'docs_article'       => new PdoDocsArticleRepository($pdoDb),
            'ticket'             => new PdoTicketRepository($pdoDb),
            default => throw new \InvalidArgumentException("Unknown repository: $key"),
        };
    }
}
