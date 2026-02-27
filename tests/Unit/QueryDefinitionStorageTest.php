<?php

namespace SchemaCraft\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use SchemaCraft\QueryBuilder\QueryDefinition;
use SchemaCraft\QueryBuilder\QueryDefinitionStorage;

class QueryDefinitionStorageTest extends TestCase
{
    private string $tempDir;

    private QueryDefinitionStorage $storage;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/schema-craft-test-queries-'.uniqid();
        $this->files = new Filesystem;
        $this->files->makeDirectory($this->tempDir, 0755, true);

        $this->storage = new QueryDefinitionStorage($this->files, $this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // ─── Save ───────────────────────────────────────────────────

    public function test_save_creates_json_file(): void
    {
        $query = $this->sampleQuery('testSave');

        $path = $this->storage->save($query);

        $this->assertFileExists($path);
        $this->assertStringEndsWith('testSave.json', $path);
    }

    public function test_save_writes_valid_json(): void
    {
        $query = $this->sampleQuery('validJson');

        $this->storage->save($query);

        $content = $this->files->get($this->tempDir.'/validJson.json');
        $data = json_decode($content, true);

        $this->assertNotNull($data);
        $this->assertEquals('validJson', $data['name']);
        $this->assertEquals('posts', $data['baseTable']);
    }

    public function test_save_sets_created_and_updated_timestamps(): void
    {
        $query = $this->sampleQuery('withTimestamps');

        $this->storage->save($query);

        $content = json_decode($this->files->get($this->tempDir.'/withTimestamps.json'), true);

        $this->assertNotNull($content['createdAt']);
        $this->assertNotNull($content['updatedAt']);
    }

    public function test_save_preserves_created_at_on_update(): void
    {
        $query = $this->sampleQuery('preserveCreated');
        $this->storage->save($query);

        $firstContent = json_decode($this->files->get($this->tempDir.'/preserveCreated.json'), true);
        $firstCreatedAt = $firstContent['createdAt'];

        // Save again (update)
        $updated = $this->sampleQuery('preserveCreated');
        $this->storage->save($updated);

        $secondContent = json_decode($this->files->get($this->tempDir.'/preserveCreated.json'), true);

        $this->assertEquals($firstCreatedAt, $secondContent['createdAt']);
    }

    // ─── Load ───────────────────────────────────────────────────

    public function test_load_returns_query_definition(): void
    {
        $original = $this->sampleQuery('loadTest');
        $this->storage->save($original);

        $loaded = $this->storage->load('loadTest');

        $this->assertInstanceOf(QueryDefinition::class, $loaded);
        $this->assertEquals('loadTest', $loaded->name);
        $this->assertEquals('posts', $loaded->baseTable);
        $this->assertEquals('App\\Models\\Post', $loaded->baseModel);
    }

    public function test_load_throws_for_nonexistent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Query definition [nonexistent] not found');

        $this->storage->load('nonexistent');
    }

    // ─── List ───────────────────────────────────────────────────

    public function test_list_returns_all_saved_queries(): void
    {
        $this->storage->save($this->sampleQuery('queryA'));
        $this->storage->save($this->sampleQuery('queryB'));
        $this->storage->save($this->sampleQuery('queryC'));

        $list = $this->storage->list();

        $this->assertCount(3, $list);
        $names = array_column($list, 'name');
        $this->assertContains('queryA', $names);
        $this->assertContains('queryB', $names);
        $this->assertContains('queryC', $names);
    }

    public function test_list_returns_empty_for_empty_directory(): void
    {
        $list = $this->storage->list();

        $this->assertEmpty($list);
    }

    public function test_list_includes_base_model(): void
    {
        $this->storage->save($this->sampleQuery('withModel'));

        $list = $this->storage->list();

        $this->assertEquals('App\\Models\\Post', $list[0]['baseModel']);
    }

    // ─── Delete ─────────────────────────────────────────────────

    public function test_delete_removes_file(): void
    {
        $this->storage->save($this->sampleQuery('toDelete'));

        $this->assertTrue($this->storage->exists('toDelete'));

        $result = $this->storage->delete('toDelete');

        $this->assertTrue($result);
        $this->assertFalse($this->storage->exists('toDelete'));
    }

    public function test_delete_returns_false_for_nonexistent(): void
    {
        $result = $this->storage->delete('nonexistent');

        $this->assertFalse($result);
    }

    // ─── Exists ─────────────────────────────────────────────────

    public function test_exists_returns_true_for_saved(): void
    {
        $this->storage->save($this->sampleQuery('existing'));

        $this->assertTrue($this->storage->exists('existing'));
    }

    public function test_exists_returns_false_for_missing(): void
    {
        $this->assertFalse($this->storage->exists('missing'));
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function sampleQuery(string $name): QueryDefinition
    {
        return QueryDefinition::fromArray([
            'name' => $name,
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'App\\Schemas\\PostSchema',
        ]);
    }
}
