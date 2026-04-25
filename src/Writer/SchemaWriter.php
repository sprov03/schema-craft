<?php

namespace SchemaCraft\Writer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class SchemaWriter
{
    private const COLLECTION_TYPES = ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany'];

    private const ATTRIBUTE_CLASSES = [
        'belongsTo' => 'SchemaCraft\Attributes\Relations\BelongsTo',
        'hasMany' => 'SchemaCraft\Attributes\Relations\HasMany',
        'hasOne' => 'SchemaCraft\Attributes\Relations\HasOne',
        'belongsToMany' => 'SchemaCraft\Attributes\Relations\BelongsToMany',
        'morphTo' => 'SchemaCraft\Attributes\Relations\MorphTo',
        'morphOne' => 'SchemaCraft\Attributes\Relations\MorphOne',
        'morphMany' => 'SchemaCraft\Attributes\Relations\MorphMany',
        'morphToMany' => 'SchemaCraft\Attributes\Relations\MorphToMany',
    ];

    private const ELOQUENT_TYPES = [
        'belongsTo' => 'BelongsTo',
        'hasMany' => 'HasMany',
        'hasOne' => 'HasOne',
        'belongsToMany' => 'BelongsToMany',
        'morphTo' => 'MorphTo',
        'morphOne' => 'MorphOne',
        'morphMany' => 'MorphMany',
        'morphToMany' => 'MorphToMany',
    ];

    public function __construct(
        private Filesystem $files,
    ) {}

    public function addRelationship(
        string $schemaFilePath,
        string $relationshipType,
        string $relatedModelFqcn,
        ?string $morphName = null,
        ?string $propertyName = null,
        array $options = [],
    ): SchemaWriteResult {
        if (! $this->files->exists($schemaFilePath)) {
            return new SchemaWriteResult(false, "Schema file not found: {$schemaFilePath}");
        }

        if (! isset(self::ATTRIBUTE_CLASSES[$relationshipType])) {
            return new SchemaWriteResult(false, "Unknown relationship type: {$relationshipType}");
        }

        $content = $this->files->get($schemaFilePath);
        $shortModel = class_basename($relatedModelFqcn);

        if ($this->isDuplicate($content, $relationshipType, $shortModel)) {
            $attrName = class_basename(self::ATTRIBUTE_CLASSES[$relationshipType]);

            return new SchemaWriteResult(false, "{$attrName}({$shortModel}::class) already exists in this schema.");
        }

        $propertyName = $propertyName ?? $this->resolvePropertyName($relationshipType, $shortModel, $morphName);
        $content = $this->applyRelationshipToContent($content, $relationshipType, $relatedModelFqcn, $shortModel, $propertyName, $morphName, $options);

        $this->files->put($schemaFilePath, $content);

        $attrName = class_basename(self::ATTRIBUTE_CLASSES[$relationshipType]);
        $schemaName = $this->extractSchemaName($content);

        return new SchemaWriteResult(true, "Added {$attrName}({$shortModel}::class) to {$schemaName}.", $propertyName);
    }

    /**
     * Preview what addRelationship would produce without writing to disk.
     * Returns the modified file content, or null if the file is missing,
     * the type is unknown, or the relationship already exists.
     */
    public function previewRelationship(
        string $schemaFilePath,
        string $relationshipType,
        string $relatedModelFqcn,
        ?string $morphName = null,
        ?string $propertyName = null,
        array $options = [],
    ): ?string {
        if (! $this->files->exists($schemaFilePath)) {
            return null;
        }

        if (! isset(self::ATTRIBUTE_CLASSES[$relationshipType])) {
            return null;
        }

        $content = $this->files->get($schemaFilePath);
        $shortModel = class_basename($relatedModelFqcn);

        if ($this->isDuplicate($content, $relationshipType, $shortModel)) {
            return null;
        }

        $propertyName = $propertyName ?? $this->resolvePropertyName($relationshipType, $shortModel, $morphName);

        return $this->applyRelationshipToContent($content, $relationshipType, $relatedModelFqcn, $shortModel, $propertyName, $morphName, $options);
    }

    private function applyRelationshipToContent(
        string $content,
        string $relationshipType,
        string $relatedModelFqcn,
        string $shortModel,
        string $propertyName,
        ?string $morphName,
        array $options = [],
    ): string {
        $content = $this->insertImports($content, $this->buildImports($relationshipType, $relatedModelFqcn, $options));
        $content = $this->insertMethodDoc($content, $this->buildMethodDocLine($relationshipType, $shortModel, $propertyName));
        $content = $this->insertPropertyBlock($content, $this->buildPropertyBlock($relationshipType, $shortModel, $propertyName, $morphName, $options));

        return $content;
    }

    private function resolvePropertyName(string $relationshipType, string $modelShortName, ?string $morphName): string
    {
        if ($relationshipType === 'morphTo') {
            return $morphName ?? 'morphable';
        }

        if (in_array($relationshipType, self::COLLECTION_TYPES)) {
            return Str::camel(Str::plural($modelShortName));
        }

        return Str::camel($modelShortName);
    }

    /**
     * @return string[]
     */
    private function buildImports(string $relationshipType, string $relatedModelFqcn, array $options = []): array
    {
        $imports = [];

        if ($relationshipType === 'morphTo') {
            $imports[] = 'Illuminate\Database\Eloquent\Model';
        } else {
            $imports[] = $relatedModelFqcn;
        }

        $imports[] = self::ATTRIBUTE_CLASSES[$relationshipType];

        if (in_array($relationshipType, self::COLLECTION_TYPES)) {
            $imports[] = 'Illuminate\Database\Eloquent\Collection';
        }

        $imports[] = 'Illuminate\Database\Eloquent\Relations as Eloquent';

        if ($relationshipType === 'belongsTo') {
            if (! empty($options['onDelete'])) {
                $imports[] = 'SchemaCraft\Attributes\OnDelete';
            }
            if (! empty($options['onUpdate'])) {
                $imports[] = 'SchemaCraft\Attributes\OnUpdate';
            }
            if (! empty($options['noConstraint'])) {
                $imports[] = 'SchemaCraft\Attributes\NoConstraint';
            }
            if (! empty($options['index'])) {
                $imports[] = 'SchemaCraft\Attributes\Index';
            }
            if (! empty($options['columnType'])) {
                $imports[] = 'SchemaCraft\Attributes\ColumnType';
            }
            if (! empty($options['with'])) {
                $imports[] = 'SchemaCraft\Attributes\With';
            }
        }

        return $imports;
    }

    private function insertImports(string $content, array $imports): string
    {
        foreach ($imports as $import) {
            if ($this->hasImport($content, $import)) {
                continue;
            }

            $content = $this->addImport($content, $import);
        }

        return $content;
    }

    private function hasImport(string $content, string $import): bool
    {
        $escaped = preg_quote($import, '/');

        return (bool) preg_match('/^use\s+\\\\?'.$escaped.'\s*;/m', $content);
    }

    private function addImport(string $content, string $import): string
    {
        $useStatement = "use {$import};";

        if (preg_match('/^use\s+.+;$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $lastUsePos = 0;
            $lastUseEnd = 0;

            if (preg_match_all('/^use\s+.+;$/m', $content, $allMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($allMatches[0] as $match) {
                    $lastUsePos = $match[1];
                    $lastUseEnd = $match[1] + strlen($match[0]);
                }
            }

            return substr($content, 0, $lastUseEnd)."\n".$useStatement.substr($content, $lastUseEnd);
        }

        if (preg_match('/^namespace\s+.+;$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $nsEnd = $matches[0][1] + strlen($matches[0][0]);

            return substr($content, 0, $nsEnd)."\n\n".$useStatement.substr($content, $nsEnd);
        }

        return $content;
    }

    private function buildMethodDocLine(string $relationshipType, string $modelShortName, string $propertyName): string
    {
        $eloquentType = self::ELOQUENT_TYPES[$relationshipType];

        if ($relationshipType === 'morphTo') {
            return "@method Eloquent\\{$eloquentType}|Model {$propertyName}()";
        }

        return "@method Eloquent\\{$eloquentType}|{$modelShortName} {$propertyName}()";
    }

    private function insertMethodDoc(string $content, string $methodLine): string
    {
        if (preg_match('/\/\*\*\s*\n(\s*\*\s*@method\s+.+\n)*\s*\*\/\nclass\s+/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $blockStart = $matches[0][1];
            $block = $matches[0][0];

            $closingPos = strrpos($block, " */\n");

            if ($closingPos !== false) {
                $newBlock = substr($block, 0, $closingPos)." * {$methodLine}\n */\n".substr($block, $closingPos + strlen(" */\n"));

                return substr($content, 0, $blockStart).$newBlock.substr($content, $blockStart + strlen($block));
            }
        }

        if (preg_match('/^class\s+\w+/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $classPos = $matches[0][1];
            $docBlock = "/**\n * {$methodLine}\n */\n";

            return substr($content, 0, $classPos).$docBlock.substr($content, $classPos);
        }

        return $content;
    }

    private function buildPropertyBlock(string $relationshipType, string $modelShortName, string $propertyName, ?string $morphName, array $options = []): string
    {
        $attrShort = class_basename(self::ATTRIBUTE_CLASSES[$relationshipType]);
        $lines = [];

        if (in_array($relationshipType, self::COLLECTION_TYPES)) {
            $lines[] = "    /** @var Collection<int, {$modelShortName}> */";
        }

        if ($relationshipType === 'morphTo') {
            $lines[] = "    #[{$attrShort}('{$morphName}')]";
            $lines[] = "    public Model \${$propertyName};";
        } elseif (in_array($relationshipType, ['morphMany', 'morphOne', 'morphToMany'])) {
            $lines[] = "    #[{$attrShort}({$modelShortName}::class, '{$morphName}')]";

            if (in_array($relationshipType, self::COLLECTION_TYPES)) {
                $lines[] = "    public Collection \${$propertyName};";
            } else {
                $lines[] = "    public {$modelShortName} \${$propertyName};";
            }
        } elseif ($relationshipType === 'belongsTo') {
            $foreignKey = $options['foreignColumn'] ?? null;
            if ($foreignKey) {
                $lines[] = "    #[{$attrShort}({$modelShortName}::class, foreignKey: '{$foreignKey}')]";
            } else {
                $lines[] = "    #[{$attrShort}({$modelShortName}::class)]";
            }
            if (! empty($options['onDelete'])) {
                $lines[] = "    #[OnDelete('{$options['onDelete']}')]";
            }
            if (! empty($options['onUpdate'])) {
                $lines[] = "    #[OnUpdate('{$options['onUpdate']}')]";
            }
            if (! empty($options['noConstraint'])) {
                $lines[] = '    #[NoConstraint]';
            }
            if (! empty($options['index'])) {
                $lines[] = '    #[Index]';
            }
            if (! empty($options['columnType'])) {
                $lines[] = "    #[ColumnType('{$options['columnType']}')]";
            }
            if (! empty($options['with'])) {
                $lines[] = '    #[With]';
            }
            $nullable = ! empty($options['nullable']) ? '?' : '';
            $lines[] = "    public {$nullable}{$modelShortName} \${$propertyName};";
        } else {
            $lines[] = "    #[{$attrShort}({$modelShortName}::class)]";

            if (in_array($relationshipType, self::COLLECTION_TYPES)) {
                $lines[] = "    public Collection \${$propertyName};";
            } else {
                $lines[] = "    public {$modelShortName} \${$propertyName};";
            }
        }

        return implode("\n", $lines);
    }

    private function insertPropertyBlock(string $content, string $propertyBlock): string
    {
        $lastBrace = strrpos($content, '}');

        if ($lastBrace === false) {
            return $content;
        }

        $before = rtrim(substr($content, 0, $lastBrace));

        return $before."\n\n".$propertyBlock."\n}\n";
    }

    private function isDuplicate(string $content, string $relationshipType, string $modelShortName): bool
    {
        $attrShort = class_basename(self::ATTRIBUTE_CLASSES[$relationshipType]);
        $pattern = '/\#\['.preg_quote($attrShort, '/').'\('.preg_quote($modelShortName, '/').'::class/';

        return (bool) preg_match($pattern, $content);
    }

    private function extractSchemaName(string $content): string
    {
        if (preg_match('/class\s+(\w+)\s+extends/', $content, $matches)) {
            return $matches[1];
        }

        return 'Schema';
    }
}
