<?php

namespace SchemaCraft\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use SchemaCraft\QueryBuilder\SchemaIndexWriter;

class SchemaIndexWriterTest extends TestCase
{
    private SchemaIndexWriter $writer;

    private Filesystem $files;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->writer = new SchemaIndexWriter($this->files);
        $this->tempDir = sys_get_temp_dir().'/schema-index-writer-test-'.uniqid();
        $this->files->makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_adds_index_attribute_to_property(): void
    {
        $path = $this->createSchemaFile('PostSchema', <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;

class PostSchema extends Schema
{
    #[Primary]
    public int $id;

    public string $status;

    public string $title;
}
PHP);

        $result = $this->writer->addIndex($path, 'status');

        $this->assertTrue($result->success);
        $content = $this->files->get($path);
        $this->assertStringContainsString("#[Index]\n    public string \$status;", $content);
    }

    public function test_adds_import_when_missing(): void
    {
        $path = $this->createSchemaFile('PostSchema', <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;

class PostSchema extends Schema
{
    #[Primary]
    public int $id;

    public string $status;
}
PHP);

        $this->writer->addIndex($path, 'status');

        $content = $this->files->get($path);
        $this->assertStringContainsString('use SchemaCraft\Attributes\Index;', $content);
    }

    public function test_does_not_duplicate_import(): void
    {
        $path = $this->createSchemaFile('PostSchema', <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\Index;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;

class PostSchema extends Schema
{
    #[Primary]
    public int $id;

    public string $status;
}
PHP);

        $this->writer->addIndex($path, 'status');

        $content = $this->files->get($path);
        $count = substr_count($content, 'use SchemaCraft\Attributes\Index;');
        $this->assertEquals(1, $count);
    }

    public function test_rejects_already_indexed_property(): void
    {
        $path = $this->createSchemaFile('PostSchema', <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\Index;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;

class PostSchema extends Schema
{
    #[Primary]
    public int $id;

    #[Index]
    public string $status;
}
PHP);

        $result = $this->writer->addIndex($path, 'status');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('already has #[Index]', $result->message);
    }

    public function test_returns_failure_for_nonexistent_file(): void
    {
        $result = $this->writer->addIndex('/nonexistent/path.php', 'status');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->message);
    }

    public function test_returns_failure_for_missing_property(): void
    {
        $path = $this->createSchemaFile('PostSchema', <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Schema;

class PostSchema extends Schema
{
    public string $title;
}
PHP);

        $result = $this->writer->addIndex($path, 'nonexistent');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->message);
    }

    public function test_adds_index_to_property_with_existing_attributes(): void
    {
        $path = $this->createSchemaFile('PostSchema', <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\BigInt;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Unsigned;
use SchemaCraft\Schema;

class PostSchema extends Schema
{
    #[Primary]
    public int $id;

    #[BigInt]
    #[Unsigned]
    public int $author_id;
}
PHP);

        $result = $this->writer->addIndex($path, 'author_id');

        $this->assertTrue($result->success);
        $content = $this->files->get($path);
        $this->assertStringContainsString("#[Unsigned]\n    #[Index]\n    public int \$author_id;", $content);
    }

    public function test_adds_indexes_to_multiple_columns(): void
    {
        $path = $this->createSchemaFile('PostSchema', <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;

class PostSchema extends Schema
{
    #[Primary]
    public int $id;

    public string $status;

    public int $author_id;
}
PHP);

        $results = $this->writer->addIndexes($path, ['status', 'author_id']);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->success);
        $this->assertTrue($results[1]->success);

        $content = $this->files->get($path);
        $this->assertStringContainsString("#[Index]\n    public string \$status;", $content);
        $this->assertStringContainsString("#[Index]\n    public int \$author_id;", $content);
    }

    public function test_result_message_includes_schema_name(): void
    {
        $path = $this->createSchemaFile('PostSchema', <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Schema;

class PostSchema extends Schema
{
    public string $status;
}
PHP);

        $result = $this->writer->addIndex($path, 'status');

        $this->assertStringContainsString('PostSchema', $result->message);
    }

    private function createSchemaFile(string $name, string $content): string
    {
        $path = $this->tempDir.'/'.$name.'.php';
        $this->files->put($path, $content);

        return $path;
    }
}
