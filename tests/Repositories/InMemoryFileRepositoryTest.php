<?php
declare(strict_types=1);
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use Repositories\InMemoryFileRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemoryFileRepositoryTest
 *
 * Full coverage of InMemoryFileRepository — 6 interface methods + 2
 * test helpers (seed, inspect). Focus on the upsert save() logic.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryFileRepositoryTest extends TestCase
{
    private InMemoryFileRepository $repo;

    private array $fileA;
    private array $fileB;

    protected function setUp(): void
    {
        $this->repo = new InMemoryFileRepository();

        $this->fileA = [
            'id'           => 'f0000001-0000-4000-8000-000000000001',
            'user_id'      => 'u1',
            'path'         => 'src/main.ts',
            'content'      => 'console.log("hello");',
            'language'     => 'typescript',
            'saved'        => 1,
            'generated'    => 0,
            'modified_at'  => '2026-06-10 14:00:00',
        ];

        $this->fileB = [
            'id'           => 'f0000002-0000-4000-8000-000000000002',
            'user_id'      => 'u1',
            'path'         => 'src/utils.ts',
            'content'      => 'export const add = (a: number, b: number) => a + b;',
            'language'     => 'typescript',
            'saved'        => 1,
            'generated'    => 0,
            'modified_at'  => '2026-06-12 09:00:00',
        ];
    }

    // ── Test helpers ───────────────────────────────────────────────

    public function test_seed_replaces_rows(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_seed_overwrites(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->repo->seed([$this->fileB]);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_inspect_returns_all_rows(): void
    {
        $this->repo->seed([$this->fileA, $this->fileB]);
        $this->assertCount(2, $this->repo->inspect());
    }

    public function test_inspect_returns_empty_when_empty(): void
    {
        $this->assertSame([], $this->repo->inspect());
    }

    // ── allForUser() ──────────────────────────────────────────────

    public function test_allForUser_returns_files_for_user(): void
    {
        $this->repo->seed([$this->fileA, $this->fileB]);
        $files = $this->repo->allForUser('u1');
        $this->assertCount(2, $files);
    }

    public function test_allForUser_returns_empty_for_other_user(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertSame([], $this->repo->allForUser('other'));
    }

    public function test_allForUser_orders_by_path_asc(): void
    {
        $fileC = array_merge($this->fileB, ['id' => 'f3', 'path' => 'README.md']);
        $this->repo->seed([$this->fileA, $this->fileB, $fileC]);
        $files = $this->repo->allForUser('u1');
        $this->assertCount(3, $files);
        // README.md < src/main.ts < src/utils.ts
        $this->assertSame('README.md', $files[0]['path']);
        $this->assertSame('src/main.ts', $files[1]['path']);
        $this->assertSame('src/utils.ts', $files[2]['path']);
    }

    public function test_allForUser_includes_size_bytes(): void
    {
        $this->repo->seed([$this->fileA]);
        $files = $this->repo->allForUser('u1');
        $this->assertArrayHasKey('size_bytes', $files[0]);
        $this->assertSame(strlen('console.log("hello");'), $files[0]['size_bytes']);
    }

    public function test_allForUser_excludes_other_users(): void
    {
        $fileOther = array_merge($this->fileA, ['id' => 'f4', 'user_id' => 'u2', 'path' => 'other.ts']);
        $this->repo->seed([$this->fileA, $fileOther]);
        $files = $this->repo->allForUser('u1');
        $this->assertCount(1, $files);
        $this->assertSame('src/main.ts', $files[0]['path']);
    }

    // ── find() ─────────────────────────────────────────────────────

    public function test_find_returns_file_when_owned(): void
    {
        $this->repo->seed([$this->fileA]);
        $file = $this->repo->find('f0000001-0000-4000-8000-000000000001', 'u1');
        $this->assertNotNull($file);
        $this->assertSame('src/main.ts', $file['path']);
    }

    public function test_find_returns_null_when_not_owned(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertNull($this->repo->find('f0000001-0000-4000-8000-000000000001', 'other'));
    }

    public function test_find_returns_null_for_missing(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertNull($this->repo->find('nonexistent', 'u1'));
    }

    // ── findByPath() ───────────────────────────────────────────────

    public function test_findByPath_returns_file_when_owned(): void
    {
        $this->repo->seed([$this->fileA]);
        $file = $this->repo->findByPath('u1', 'src/main.ts');
        $this->assertNotNull($file);
        $this->assertSame('typescript', $file['language']);
    }

    public function test_findByPath_returns_null_for_missing_path(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertNull($this->repo->findByPath('u1', 'nonexistent.ts'));
    }

    public function test_findByPath_returns_null_for_wrong_user(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertNull($this->repo->findByPath('other', 'src/main.ts'));
    }

    // ── save() — insert path ───────────────────────────────────────

    public function test_save_inserts_new_file(): void
    {
        $id = $this->repo->save('u1', 'new.ts', 'const x = 1;', 'typescript', false);
        $this->assertNotEmpty($id);

        $file = $this->repo->find($id, 'u1');
        $this->assertNotNull($file);
        $this->assertSame('new.ts', $file['path']);
        $this->assertSame('const x = 1;', $file['content']);
        $this->assertSame('typescript', $file['language']);
        $this->assertSame(0, $file['generated']);
    }

    public function test_save_inserts_with_generated_flag(): void
    {
        $id = $this->repo->save('u1', 'gen.ts', '// generated', 'typescript', true);
        $file = $this->repo->find($id, 'u1');
        $this->assertSame(1, $file['generated']);
    }

    public function test_save_inserts_with_null_content(): void
    {
        $id = $this->repo->save('u1', 'meta.ts', null, 'typescript', false);
        $file = $this->repo->find($id, 'u1');
        $this->assertNull($file['content']);
        $this->assertSame(1, $file['saved']);
    }

    // ── save() — update path (upsert) ──────────────────────────────

    public function test_save_updates_existing_file_by_path(): void
    {
        $this->repo->seed([$this->fileA]);

        // Save again with same user+path, different content
        $id = $this->repo->save('u1', 'src/main.ts', 'console.log("updated");', 'typescript', false);
        $this->assertSame('f0000001-0000-4000-8000-000000000001', $id);  // same id

        $file = $this->repo->find('f0000001-0000-4000-8000-000000000001', 'u1');
        $this->assertSame('console.log("updated");', $file['content']);
        $this->assertNotEmpty($file['modified_at']);
    }

    public function test_save_updates_language_on_upsert(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->repo->save('u1', 'src/main.ts', 'console.log("x");', 'javascript', false);
        $file = $this->repo->find('f0000001-0000-4000-8000-000000000001', 'u1');
        $this->assertSame('javascript', $file['language']);
    }

    public function test_save_updates_generated_on_upsert(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->repo->save('u1', 'src/main.ts', 'updated', 'typescript', true);
        $file = $this->repo->find('f0000001-0000-4000-8000-000000000001', 'u1');
        $this->assertSame(1, $file['generated']);
    }

    public function test_save_does_not_duplicate_on_upsert(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->repo->save('u1', 'src/main.ts', 'updated', 'typescript', false);
        $files = $this->repo->allForUser('u1');
        $this->assertCount(1, $files);  // still 1 file, not 2
    }

    public function test_save_inserts_new_file_when_path_is_different(): void
    {
        $this->repo->seed([$this->fileA]);
        $id = $this->repo->save('u1', 'different.ts', 'other', 'typescript', false);
        $this->assertNotSame('f0000001-0000-4000-8000-000000000001', $id);
        $this->assertCount(2, $this->repo->allForUser('u1'));
    }

    // ── delete() ───────────────────────────────────────────────────

    public function test_delete_removes_file_when_owned(): void
    {
        $this->repo->seed([$this->fileA, $this->fileB]);
        $this->repo->delete('f0000001-0000-4000-8000-000000000001', 'u1');
        $this->assertCount(1, $this->repo->inspect());
        $this->assertNull($this->repo->find('f0000001-0000-4000-8000-000000000001', 'u1'));
    }

    public function test_delete_does_nothing_when_not_owned(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->repo->delete('f0000001-0000-4000-8000-000000000001', 'other');
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_delete_does_nothing_for_missing(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->repo->delete('nonexistent', 'u1');
        $this->assertCount(1, $this->repo->inspect());
    }

    // ── deleteByPrefix() — folder delete ──────────────────────────

    public function test_deleteByPrefix_removes_files_under_folder(): void
    {
        $this->repo->seed([$this->fileA, $this->fileB]); // both under src/
        $count = $this->repo->deleteByPrefix('u1', 'src');
        $this->assertSame(2, $count);
        $this->assertCount(0, $this->repo->inspect());
    }

    public function test_deleteByPrefix_exact_path_match(): void
    {
        $fileC = array_merge($this->fileB, ['id' => 'f3', 'path' => 'src.ts']);
        $this->repo->seed([$this->fileA, $fileC]);
        // 'src.ts' is a file, not a folder — exact path match
        $count = $this->repo->deleteByPrefix('u1', 'src.ts');
        $this->assertSame(1, $count);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_deleteByPrefix_does_not_touch_sibling_prefixes(): void
    {
        $fileC = array_merge($this->fileB, ['id' => 'f3', 'path' => 'src-other/file.ts']);
        $this->repo->seed([$this->fileA, $fileC]);
        $count = $this->repo->deleteByPrefix('u1', 'src');
        $this->assertSame(1, $count); // only src/main.ts
        $files = $this->repo->allForUser('u1');
        $this->assertCount(1, $files);
        $this->assertSame('src-other/file.ts', $files[0]['path']);
    }

    public function test_deleteByPrefix_scoped_to_user(): void
    {
        $fileOther = array_merge($this->fileA, ['id' => 'f4', 'user_id' => 'u2']);
        $this->repo->seed([$this->fileA, $fileOther]);
        $count = $this->repo->deleteByPrefix('u1', 'src');
        $this->assertSame(1, $count);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_deleteByPrefix_empty_prefix_deletes_nothing(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertSame(0, $this->repo->deleteByPrefix('u1', ''));
        $this->assertSame(0, $this->repo->deleteByPrefix('u1', '/'));
        $this->assertSame(0, $this->repo->deleteByPrefix('u1', '///'));
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_deleteByPrefix_no_match_returns_zero(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertSame(0, $this->repo->deleteByPrefix('u1', 'nonexistent'));
    }

    public function test_deleteByPrefix_trailing_slash(): void
    {
        $this->repo->seed([$this->fileA, $this->fileB]);
        $count = $this->repo->deleteByPrefix('u1', 'src/');
        $this->assertSame(2, $count);
    }

    // ── Folder markers (empty-folder placeholder rows) ─────────────

    public function test_save_can_create_folder_marker(): void
    {
        $id = $this->repo->save('u1', 'assets/', '', 'markdown', false);
        $marker = $this->repo->find($id, 'u1');
        $this->assertSame('assets/', $marker['path']);
        $this->assertSame('', $marker['content']);
    }

    public function test_deleteByPrefix_removes_folder_marker(): void
    {
        $marker = array_merge($this->fileA, ['id' => 'f-marker', 'path' => 'assets/', 'content' => '']);
        $this->repo->seed([$marker, $this->fileA]);
        // Deleting the empty folder also removes its placeholder row.
        $count = $this->repo->deleteByPrefix('u1', 'assets');
        $this->assertSame(1, $count);
        $this->assertCount(1, $this->repo->inspect());
    }

    // ── rename() — file & folder rename ────────────────────────────

    public function test_rename_moves_file(): void
    {
        $this->repo->seed([$this->fileA]);
        $result = $this->repo->rename('u1', 'src/main.ts', 'src/entry.ts');
        $this->assertSame(1, $result['renamed']);
        $this->assertNull($this->repo->findByPath('u1', 'src/main.ts'));
        $this->assertNotNull($this->repo->findByPath('u1', 'src/entry.ts'));
    }

    public function test_rename_moves_folder_with_children(): void
    {
        $this->repo->seed([$this->fileA, $this->fileB]); // src/main.ts, src/utils.ts
        $result = $this->repo->rename('u1', 'src', 'lib');
        $this->assertSame(2, $result['renamed']);
        $this->assertNotNull($this->repo->findByPath('u1', 'lib/main.ts'));
        $this->assertNotNull($this->repo->findByPath('u1', 'lib/utils.ts'));
        $this->assertCount(2, $this->repo->allForUser('u1'));
    }

    public function test_rename_moves_folder_marker(): void
    {
        $marker = array_merge($this->fileA, ['id' => 'f-marker', 'path' => 'assets/', 'content' => '']);
        $this->repo->seed([$marker]);
        $result = $this->repo->rename('u1', 'assets', 'media');
        $this->assertSame(1, $result['renamed']);
        $this->assertNotNull($this->repo->findByPath('u1', 'media/'));
        $this->assertNull($this->repo->findByPath('u1', 'assets/'));
    }

    public function test_rename_conflict_returns_error(): void
    {
        $fileC = array_merge($this->fileB, ['id' => 'f3', 'path' => 'lib/main.ts']);
        $this->repo->seed([$this->fileA, $fileC]); // src/main.ts + lib/main.ts
        $result = $this->repo->rename('u1', 'src', 'lib');
        $this->assertSame('conflict', $result['error']);
        $this->assertSame(0, $result['renamed']);
        // Nothing was moved.
        $this->assertNotNull($this->repo->findByPath('u1', 'src/main.ts'));
        $this->assertNotNull($this->repo->findByPath('u1', 'lib/main.ts'));
    }

    public function test_rename_not_found_returns_error(): void
    {
        $this->repo->seed([$this->fileA]);
        $result = $this->repo->rename('u1', 'missing', 'gone');
        $this->assertSame('not_found', $result['error']);
    }

    public function test_rename_same_path_is_noop(): void
    {
        $this->repo->seed([$this->fileA]);
        $result = $this->repo->rename('u1', 'src/main.ts', 'src/main.ts');
        $this->assertTrue($result['same']);
        $this->assertSame(0, $result['renamed']);
        $this->assertNotNull($this->repo->findByPath('u1', 'src/main.ts'));
    }

    public function test_rename_empty_path_invalid(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertSame('invalid', $this->repo->rename('u1', '', 'x')['error']);
        $this->assertSame('invalid', $this->repo->rename('u1', 'src/main.ts', '')['error']);
    }

    public function test_rename_nested_move_rejected(): void
    {
        $this->repo->seed([$this->fileA]);
        // A folder can't be moved into itself ('src' → 'src/main').
        $result = $this->repo->rename('u1', 'src', 'src/main');
        $this->assertSame('nested_move', $result['error']);
        $this->assertNotNull($this->repo->findByPath('u1', 'src/main.ts'));
    }

    public function test_rename_scoped_to_user(): void
    {
        $fileOther = array_merge($this->fileA, ['id' => 'f4', 'user_id' => 'u2']);
        $this->repo->seed([$fileOther]);
        $result = $this->repo->rename('u1', 'src/main.ts', 'moved.ts');
        $this->assertSame('not_found', $result['error']);
        // Other user's file untouched.
        $this->assertNotNull($this->repo->findByPath('u2', 'src/main.ts'));
    }

    // ── duplicate() — file duplication ────────────────────────────

    public function test_duplicate_copies_file(): void
    {
        $this->repo->seed([$this->fileA]);
        $result = $this->repo->duplicate('u1', 'src/main.ts');
        $this->assertSame(1, $result['duplicated']);
        $this->assertSame('src/main (copy).ts', $result['path']);

        $this->assertCount(2, $this->repo->allForUser('u1'));
        $copy = $this->repo->findByPath('u1', 'src/main (copy).ts');
        $this->assertNotNull($copy);
        $this->assertSame($this->fileA['content'], $copy['content']);
        $this->assertSame($this->fileA['language'], $copy['language']);
        $this->assertSame($this->fileA['generated'], $copy['generated']);
        $this->assertNotSame($this->fileA['id'], $copy['id']);
        $this->assertNotSame($this->fileA['path'], $copy['path']);
    }

    public function test_duplicate_increments_suffix_on_collision(): void
    {
        $copy1 = array_merge($this->fileA, ['id' => 'f-c1', 'path' => 'src/main (copy).ts']);
        $this->repo->seed([$this->fileA, $copy1]);
        $result = $this->repo->duplicate('u1', 'src/main.ts');
        $this->assertSame('src/main (copy 2).ts', $result['path']);
    }

    public function test_duplicate_file_without_extension(): void
    {
        $noExt = array_merge($this->fileA, ['id' => 'f-noext', 'path' => 'LICENSE']);
        $this->repo->seed([$noExt]);
        $result = $this->repo->duplicate('u1', 'LICENSE');
        $this->assertSame('LICENSE (copy)', $result['path']);
    }

    public function test_duplicate_dotfile_keeps_leading_dot(): void
    {
        $dot = array_merge($this->fileA, ['id' => 'f-dot', 'path' => '.gitignore', 'content' => 'vendor/']);
        $this->repo->seed([$dot]);
        $result = $this->repo->duplicate('u1', '.gitignore');
        // '.gitignore' must become '.gitignore (copy)', not ' (copy).gitignore'.
        $this->assertSame('.gitignore (copy)', $result['path']);
        $this->assertNotNull($this->repo->findByPath('u1', '.gitignore (copy)'));
    }

    public function test_duplicate_not_found_returns_error(): void
    {
        $this->repo->seed([$this->fileA]);
        $result = $this->repo->duplicate('u1', 'missing.ts');
        $this->assertSame('not_found', $result['error']);
    }

    public function test_duplicate_empty_path_invalid(): void
    {
        $this->repo->seed([$this->fileA]);
        $this->assertSame('invalid', $this->repo->duplicate('u1', '')['error']);
    }

    public function test_duplicate_scoped_to_user(): void
    {
        $fileOther = array_merge($this->fileA, ['id' => 'f4', 'user_id' => 'u2']);
        $this->repo->seed([$fileOther]);
        $result = $this->repo->duplicate('u1', 'src/main.ts');
        $this->assertSame('not_found', $result['error']);
        $this->assertCount(1, $this->repo->inspect());
    }

    // ── countAll() ─────────────────────────────────────────────────

    public function test_countAll_returns_total(): void
    {
        $this->repo->seed([$this->fileA, $this->fileB]);
        $this->assertSame(['c' => 2], $this->repo->countAll());
    }

    public function test_countAll_returns_zero_when_empty(): void
    {
        $this->assertSame(['c' => 0], $this->repo->countAll());
    }

    // ── totalBytes() — per-user storage quota ─────────────────────

    public function test_totalBytes_sums_content_length(): void
    {
        $this->repo->seed([$this->fileA, $this->fileB]);
        $expected = strlen($this->fileA['content']) + strlen($this->fileB['content']);
        $this->assertSame($expected, $this->repo->totalBytes('u1'));
    }

    public function test_totalBytes_ignores_other_users(): void
    {
        $fileOther = array_merge($this->fileA, ['id' => 'f4', 'user_id' => 'u2', 'content' => str_repeat('z', 1000)]);
        $this->repo->seed([$this->fileA, $fileOther]);
        $this->assertSame(strlen($this->fileA['content']), $this->repo->totalBytes('u1'));
        $this->assertSame(1000, $this->repo->totalBytes('u2'));
    }

    public function test_totalBytes_zero_when_empty(): void
    {
        $this->assertSame(0, $this->repo->totalBytes('u1'));
    }

    public function test_totalBytes_counts_null_content_as_zero(): void
    {
        $this->repo->save('u1', 'meta.ts', null, 'typescript', false);
        $this->assertSame(0, $this->repo->totalBytes('u1'));
    }

    // ── Registry integration ───────────────────────────────────────

    public function test_registry_returns_file_repo(): void
    {
        $repo = \Repositories\RepositoryRegistry::file();
        $this->assertInstanceOf(\Repositories\FileRepository::class, $repo);
    }

    public function test_registry_can_swap_file_repo(): void
    {
        $inMemory = new InMemoryFileRepository();
        $inMemory->seed([$this->fileA]);

        $old = \Repositories\RepositoryRegistry::swap('file', $inMemory);
        try {
            $file = \Repositories\RepositoryRegistry::file()->find(
                'f0000001-0000-4000-8000-000000000001', 'u1'
            );
            $this->assertSame('src/main.ts', $file['path']);
        } finally {
            \Repositories\RepositoryRegistry::swap('file', $old);
        }
    }
}
