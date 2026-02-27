<?php

namespace SchemaCraft\QueryBuilder;

use Illuminate\Filesystem\Filesystem;

class SchemaIndexWriter
{
    public function __construct(
        private Filesystem $files,
    ) {}

    /**
     * Add #[Index] attribute to a property in a schema file.
     */
    public function addIndex(string $schemaFilePath, string $columnName): SchemaIndexWriteResult
    {
        if (! $this->files->exists($schemaFilePath)) {
            return new SchemaIndexWriteResult(false, "Schema file not found: {$schemaFilePath}");
        }

        $content = $this->files->get($schemaFilePath);

        // Find the property line for this column (use [ \t]* for indent to avoid capturing newlines)
        $pattern = '/^([ \t]*)public\s+\S+\s+\$'.preg_quote($columnName, '/').'\s*[;=]/m';

        if (! preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return new SchemaIndexWriteResult(false, "Property \${$columnName} not found in schema file.");
        }

        $propertyPos = $matches[0][1];

        // Check if #[Index] already exists in the attribute block above this property
        // Walk backward from the property line through preceding attribute lines
        $before = substr($content, 0, $propertyPos);
        $lines = explode("\n", $before);

        // Walk backward (skip empty last element from trailing newline)
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];

            // Skip empty trailing element from the split
            if ($i === count($lines) - 1 && $line === '') {
                continue;
            }

            if (preg_match('/^\s*#\[Index\]/', $line)) {
                return new SchemaIndexWriteResult(false, "Property \${$columnName} already has #[Index] attribute.");
            }

            // Stop if we hit a blank line, another property, or a class/use declaration
            if (trim($line) === '' || preg_match('/^\s*public\s+/', $line) || preg_match('/^\s*(class|use|namespace)\s+/', $line)) {
                break;
            }
        }

        // Ensure the Index import exists
        $content = $this->ensureImport($content, 'SchemaCraft\Attributes\Index');

        // Re-find the property position (import may have shifted offsets)
        preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
        $propertyPos = $matches[0][1];
        $indent = $matches[1][0];

        // Insert #[Index] on the line before the property
        $indexLine = $indent."#[Index]\n";
        $content = substr($content, 0, $propertyPos).$indexLine.substr($content, $propertyPos);

        $this->files->put($schemaFilePath, $content);

        $schemaName = $this->extractSchemaName($content);

        return new SchemaIndexWriteResult(true, "Added #[Index] to \${$columnName} in {$schemaName}.");
    }

    /**
     * Add #[Index] to multiple columns in a schema file.
     *
     * @param  array<int, string>  $columnNames
     * @return array<int, SchemaIndexWriteResult>
     */
    public function addIndexes(string $schemaFilePath, array $columnNames): array
    {
        $results = [];

        foreach ($columnNames as $columnName) {
            $results[] = $this->addIndex($schemaFilePath, $columnName);
        }

        return $results;
    }

    private function ensureImport(string $content, string $import): string
    {
        $escaped = preg_quote($import, '/');

        if (preg_match('/^use\s+\\\\?'.$escaped.'\s*;/m', $content)) {
            return $content;
        }

        $useStatement = "use {$import};";

        if (preg_match_all('/^use\s+.+;$/m', $content, $allMatches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($allMatches[0]);
            $lastUseEnd = $lastMatch[1] + strlen($lastMatch[0]);

            return substr($content, 0, $lastUseEnd)."\n".$useStatement.substr($content, $lastUseEnd);
        }

        if (preg_match('/^namespace\s+.+;$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $nsEnd = $matches[0][1] + strlen($matches[0][0]);

            return substr($content, 0, $nsEnd)."\n\n".$useStatement.substr($content, $nsEnd);
        }

        return $content;
    }

    private function extractSchemaName(string $content): string
    {
        if (preg_match('/class\s+(\w+)\s+extends/', $content, $matches)) {
            return $matches[1];
        }

        return 'Schema';
    }
}
