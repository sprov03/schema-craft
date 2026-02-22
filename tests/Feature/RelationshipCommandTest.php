<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Tests\TestCase;

class RelationshipCommandTest extends TestCase
{
    private Filesystem $files;

    private string $schemasDir;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['env'] = 'local';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->schemasDir = app_path('Schemas');
        $this->files->ensureDirectoryExists($this->schemasDir);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->schemasDir);

        parent::tearDown();
    }

    private function createSchemaFile(string $name): string
    {
        $path = $this->schemasDir."/{$name}Schema.php";
        $content = <<<PHP
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class {$name}Schema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int \$id;
}
PHP;

        $this->files->put($path, $content);

        return $path;
    }

    public function test_adds_single_relationship(): void
    {
        $userPath = $this->createSchemaFile('User');
        $this->createSchemaFile('Account');

        $this->artisan('schema-craft:relationship', [
            'definition' => 'User->belongsTo(Account)',
        ])->assertSuccessful();

        $content = $this->files->get($userPath);

        $this->assertStringContainsString('#[BelongsTo(Account::class)]', $content);
        $this->assertStringContainsString('public Account $account;', $content);
    }

    public function test_adds_relationship_with_inverse(): void
    {
        $userPath = $this->createSchemaFile('User');
        $accountPath = $this->createSchemaFile('Account');

        $this->artisan('schema-craft:relationship', [
            'definition' => 'User->belongsTo(Account)->hasMany(User)',
        ])->assertSuccessful();

        $userContent = $this->files->get($userPath);
        $accountContent = $this->files->get($accountPath);

        $this->assertStringContainsString('#[BelongsTo(Account::class)]', $userContent);
        $this->assertStringContainsString('#[HasMany(User::class)]', $accountContent);
    }

    public function test_adds_relationship_with_custom_property_names(): void
    {
        $userPath = $this->createSchemaFile('User');
        $this->createSchemaFile('Account');

        $this->artisan('schema-craft:relationship', [
            'definition' => 'User->$myAccount:belongsTo(Account)',
        ])->assertSuccessful();

        $content = $this->files->get($userPath);

        $this->assertStringContainsString('public Account $myAccount;', $content);
    }

    public function test_handles_studly_case_types(): void
    {
        $userPath = $this->createSchemaFile('User');
        $this->createSchemaFile('Account');

        $this->artisan('schema-craft:relationship', [
            'definition' => 'User->BelongsTo(Account)',
        ])->assertSuccessful();

        $content = $this->files->get($userPath);

        $this->assertStringContainsString('#[BelongsTo(Account::class)]', $content);
    }

    public function test_fails_when_schema_not_found(): void
    {
        $this->artisan('schema-craft:relationship', [
            'definition' => 'NonExistent->belongsTo(Account)',
        ])->assertFailed();
    }

    public function test_fails_in_non_local_environment(): void
    {
        $this->app['env'] = 'production';

        $this->createSchemaFile('User');
        $this->createSchemaFile('Account');

        $this->artisan('schema-craft:relationship', [
            'definition' => 'User->belongsTo(Account)',
        ])->assertFailed();
    }

    public function test_outputs_success_message(): void
    {
        $this->createSchemaFile('User');
        $this->createSchemaFile('Account');

        $this->artisan('schema-craft:relationship', [
            'definition' => 'User->belongsTo(Account)',
        ])->expectsOutputToContain('Added BelongsTo(Account::class) to UserSchema')
            ->assertSuccessful();
    }

    public function test_alias_works(): void
    {
        $userPath = $this->createSchemaFile('User');
        $this->createSchemaFile('Account');

        $this->artisan('schema-craft:rel', [
            'definition' => 'User->belongsTo(Account)',
        ])->assertSuccessful();

        $content = $this->files->get($userPath);

        $this->assertStringContainsString('#[BelongsTo(Account::class)]', $content);
    }
}
