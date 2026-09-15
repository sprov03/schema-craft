<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates a docblock stub file so IDEs autocomplete everything a schema implies but the
 * class doesn't literally declare: relationship METHODS (`$model->inTheCareOf()`) and
 * SYNTHESIZED columns (belongsTo FKs, morph _type/_id). Real schema properties already
 * complete via the model's `@mixin <Schema>`, so they are deliberately not re-emitted.
 *
 * Fail-safe by design: the whole file is built in memory first, and if ANY schema fails to
 * scan (typically a schema mid-edit), nothing is written and the previous helper file is
 * left untouched — wired to an on-save File Watcher, a broken save must never wipe the
 * completions for every other schema.
 */
class IdeHelperCommand extends Command
{
    protected $signature = 'schema:ide-helper
        {--schemas= : Comma-separated schema classes (default: discover all)}
        {--output= : Output file path (default: _ide_helper.schema-craft.php in base_path)}';

    protected $description = 'Generate IDE docblock stubs (relationship methods + synthesized columns) from schemas';

    /** Relation types whose loaded value is a Collection — they get a generic @property. */
    private const TO_MANY_TYPES = ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'hasManyThrough'];

    /** Maps RelationshipDefinition->type to the Eloquent relation class for @method return types. */
    private const RELATION_CLASSES = [
        'belongsTo' => '\Illuminate\Database\Eloquent\Relations\BelongsTo',
        'hasOne' => '\Illuminate\Database\Eloquent\Relations\HasOne',
        'hasMany' => '\Illuminate\Database\Eloquent\Relations\HasMany',
        'belongsToMany' => '\Illuminate\Database\Eloquent\Relations\BelongsToMany',
        'morphTo' => '\Illuminate\Database\Eloquent\Relations\MorphTo',
        'morphOne' => '\Illuminate\Database\Eloquent\Relations\MorphOne',
        'morphMany' => '\Illuminate\Database\Eloquent\Relations\MorphMany',
        'morphToMany' => '\Illuminate\Database\Eloquent\Relations\MorphToMany',
        'hasOneThrough' => '\Illuminate\Database\Eloquent\Relations\HasOneThrough',
        'hasManyThrough' => '\Illuminate\Database\Eloquent\Relations\HasManyThrough',
    ];

    public function handle(): int
    {
        $schemaClasses = $this->resolveSchemaClasses();

        if ($schemaClasses === []) {
            $this->warn('No schemas found.');

            return self::SUCCESS;
        }

        // Build every stub in memory BEFORE touching disk. Any failure aborts the whole run
        // so a schema mid-edit can't destroy the helper entries of the healthy schemas.
        $blocks = [];
        $errors = [];

        foreach ($schemaClasses as $schemaClass) {
            try {
                $blocks[] = $this->buildStubBlock($schemaClass);
            } catch (\Throwable $e) {
                $errors[] = "{$schemaClass}: {$e->getMessage()}";
            }
        }

        if ($errors !== []) {
            $this->error('ide-helper NOT updated — '.count($errors).' schema(s) failed to scan:');

            foreach ($errors as $error) {
                $this->error("  ✗ {$error}");
            }

            return self::FAILURE;
        }

        $output = $this->option('output') ?: base_path('_ide_helper.schema-craft.php');

        file_put_contents($output, $this->renderFile($blocks));

        $this->info(count($blocks).' schema stub(s) written to '.$output);
        $this->comment('Add the file to .gitignore — it is generated, per-machine output.');

        return self::SUCCESS;
    }

    /** @return class-string[] */
    private function resolveSchemaClasses(): array
    {
        if ($csv = $this->option('schemas')) {
            return array_values(array_filter(array_map('trim', explode(',', $csv))));
        }

        return (new SchemaDiscovery)->discover(ConfigResolver::schemaDirectories());
    }

    /**
     * Build one `namespace { class stub }` block for a schema.
     *
     * The stub redeclares the class with only a docblock body — the same pattern
     * barryvdh/laravel-ide-helper uses. IDEs merge the docblock into the real class;
     * the file is never autoloaded so the duplicate declaration is inert.
     */
    private function buildStubBlock(string $schemaClass): string
    {
        if (! class_exists($schemaClass)) {
            throw new \RuntimeException('Class does not exist (or failed to load).');
        }

        $table = (new SchemaScanner($schemaClass))->scan();
        $reflection = new ReflectionClass($schemaClass);

        $lines = [];

        // Synthesized columns only — anything the scanner derived that isn't a real property
        // (belongsTo FKs, morph _type/_id). Real properties complete on their own.
        foreach ($table->columns as $column) {
            if ($reflection->hasProperty($column->name)) {
                continue;
            }

            $type = $this->docblockColumnType($column);
            $lines[] = " * @property {$type} \${$column->name}";
        }

        foreach ($table->relationships as $rel) {
            // For to-many relations, the @method types the builder call ($model->rel()->...), but
            // $model->rel (the loaded collection) needs a generic @property so iterating it completes
            // the element type. Plain @property — these are the real, writable app models.
            if (in_array($rel->type, self::TO_MANY_TYPES, true)) {
                $element = '\\'.ltrim($rel->relatedModel, '\\');
                $lines[] = " * @property \\Illuminate\\Database\\Eloquent\\Collection<int, {$element}> \${$rel->name}";
            }

            $lines[] = ' * @method '.$this->relationReturnType($rel, $reflection)." {$rel->name}()";
        }

        $namespace = $reflection->getNamespaceName();
        $shortName = $reflection->getShortName();
        $docblock = implode("\n", array_merge(
            ['/**', " * IDE stubs for {$schemaClass} — generated by schema:ide-helper. DO NOT EDIT."],
            $lines,
            [' */'],
        ));

        return "namespace {$namespace} {\n{$docblock}\n    class {$shortName} {}\n}";
    }

    /**
     * Docblock type for a synthesized column. These are always scalar (FK ints, morph type
     * strings), but route enum/date casts to resolvable FQCNs anyway so an explicit-override
     * edge case never emits a bare short name the stub's namespace can't resolve.
     */
    private function docblockColumnType(\SchemaCraft\Scanner\ColumnDefinition $definition): string
    {
        $column = new GeneratorColumn($definition);

        $type = match (true) {
            $column->isEnum() => '\\'.ltrim($column->enumClass(), '\\'),
            default => $column->phpType(),
        };

        if ($type === 'CarbonInterface') {
            $type = '\Carbon\CarbonInterface';
        }

        return $definition->nullable ? "{$type}|null" : $type;
    }

    /**
     * @method return type: the relation class unioned with the concrete target(s), e.g.
     * `MorphTo|\App\Models\LeadEntity|\App\Models\LeadContact`. For morphTo the targets come
     * from the property's declared union type (the scanner stores only the Model placeholder).
     */
    private function relationReturnType(RelationshipDefinition $rel, ReflectionClass $reflection): string
    {
        $relationClass = self::RELATION_CLASSES[$rel->type] ?? '\Illuminate\Database\Eloquent\Relations\Relation';

        $targets = $rel->type === 'morphTo'
            ? $this->morphTargetsFromProperty($reflection, $rel->name)
            : ['\\'.ltrim($rel->relatedModel, '\\')];

        return implode('|', array_merge([$relationClass], $targets));
    }

    /** @return string[] FQCNs from the property's union type, or [] for Model/interface-typed. */
    private function morphTargetsFromProperty(ReflectionClass $reflection, string $propertyName): array
    {
        if (! $reflection->hasProperty($propertyName)) {
            return [];
        }

        $type = $reflection->getProperty($propertyName)->getType();

        if ($type instanceof ReflectionUnionType) {
            $targets = [];

            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType && ! $member->isBuiltin()) {
                    $targets[] = '\\'.ltrim($member->getName(), '\\');
                }
            }

            return $targets;
        }

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()
            && $type->getName() !== \Illuminate\Database\Eloquent\Model::class) {
            return ['\\'.ltrim($type->getName(), '\\')];
        }

        return [];
    }

    /** @param string[] $blocks */
    private function renderFile(array $blocks): string
    {
        $header = <<<'PHP'
<?php

/**
 * Generated by `php artisan schema:ide-helper` — DO NOT EDIT, DO NOT COMMIT (gitignore it).
 *
 * Docblock stubs the IDE merges into the real schema classes: relationship methods and
 * synthesized columns (belongsTo FKs, morph _type/_id). Never autoloaded at runtime.
 */

PHP;

        return $header."\n".implode("\n\n", $blocks)."\n";
    }
}
