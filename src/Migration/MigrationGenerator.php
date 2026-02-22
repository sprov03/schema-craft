<?php

namespace SchemaCraft\Migration;

/**
 * Renders TableDiff objects into standard Laravel migration file strings.
 *
 * All column rendering uses CanonicalColumn — both up() (desired state)
 * and down() (actual state) go through the same renderCanonicalColumn() method.
 */
class MigrationGenerator
{
    /**
     * Generate migration PHP code from a TableDiff.
     */
    public function generate(TableDiff $diff): string
    {
        if ($diff->type === 'create') {
            return $this->generateCreate($diff);
        }

        return $this->generateUpdate($diff);
    }

    /**
     * Write a migration file to disk.
     *
     * @return string The file path that was written.
     */
    public function write(TableDiff $diff, string $migrationPath): string
    {
        $content = $this->generate($diff);
        $action = $diff->type === 'create' ? 'create' : 'update';
        $filename = date('Y_m_d_His')."_{$action}_{$diff->tableName}_table.php";
        $path = rtrim($migrationPath, '/').'/'.$filename;

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Columns managed by timestamps() and softDeletes() that should be
     * skipped when rendered individually (they are handled by their shorthand).
     */
    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const SOFT_DELETE_COLUMNS = ['deleted_at'];

    /**
     * Generate a "create table" migration.
     */
    private function generateCreate(TableDiff $diff): string
    {
        $lines = [];
        $skipColumns = $this->managedColumnNames($diff);

        foreach ($diff->columnDiffs as $colDiff) {
            if ($colDiff->action !== 'add' || $colDiff->desired === null) {
                continue;
            }

            // Skip columns handled by $table->timestamps() / $table->softDeletes()
            if (in_array($colDiff->columnName, $skipColumns, true)) {
                continue;
            }

            $col = $colDiff->desired;

            // Use shorthand $table->id() for auto-incrementing PK
            if ($col->primary && $col->autoIncrement) {
                $lines[] = $this->renderIdColumn($col);

                continue;
            }

            $lines[] = $this->renderCanonicalColumn($col);
        }

        // Foreign key constraints
        foreach ($diff->fkDiffs as $fk) {
            if ($fk->action === 'add' && ! $fk->noConstraint) {
                $lines[] = $this->renderForeignKey($fk);
            }
        }

        if ($diff->addTimestamps) {
            $lines[] = '$table->timestamps();';
        }

        if ($diff->addSoftDeletes) {
            $lines[] = '$table->softDeletes();';
        }

        // Composite indexes
        foreach ($diff->indexDiffs as $idxDiff) {
            if ($idxDiff->action === 'add' && count($idxDiff->columns) > 1) {
                $cols = $this->renderArray($idxDiff->columns);
                $lines[] = '$table->index('.$cols.');';
            }
        }

        $upBody = $this->indent($lines, 3);

        $downBody = "        Schema::dropIfExists('{$diff->tableName}');";

        return $this->wrapMigration(
            "Schema::create('{$diff->tableName}', function (Blueprint \$table) {\n{$upBody}\n        });",
            $downBody,
        );
    }

    /**
     * Generate an "update table" migration.
     *
     * Ordering matters:
     *   up()   → renames first, then adds, modifies, drops
     *   down() → modify-reverts first, then rename-reverts last
     *
     * This ensures renameColumn() runs before any ->change() on the new name,
     * and down() correctly reverses: revert the type first (while still called
     * the new name), then rename back.
     */
    private function generateUpdate(TableDiff $diff): string
    {
        $upLines = [];
        $downLines = [];
        $downRenameLines = [];  // rename-reverts — appended AFTER $downLines in down()
        $skipColumns = $this->managedColumnNames($diff);

        // Rename columns (rendered first in up)
        foreach ($diff->columnDiffs as $colDiff) {
            if ($colDiff->action === 'rename' && $colDiff->oldColumnName !== null) {
                $upLines[] = "\$table->renameColumn('{$colDiff->oldColumnName}', '{$colDiff->columnName}');";
                $downRenameLines[] = "\$table->renameColumn('{$colDiff->columnName}', '{$colDiff->oldColumnName}');";
            }
        }

        // Add columns
        foreach ($diff->columnDiffs as $colDiff) {
            if ($colDiff->action === 'add' && $colDiff->desired !== null) {
                if (in_array($colDiff->columnName, $skipColumns, true)) {
                    continue;
                }

                $upLines[] = $this->renderCanonicalColumn($colDiff->desired);
                $downLines[] = "\$table->dropColumn('{$colDiff->columnName}');";
            }
        }

        // Modify columns
        foreach ($diff->columnDiffs as $colDiff) {
            if ($colDiff->action === 'modify' && $colDiff->desired !== null) {
                if (in_array($colDiff->columnName, $skipColumns, true)) {
                    continue;
                }

                $upLines[] = $this->renderCanonicalColumn($colDiff->desired, change: true);

                if ($colDiff->actual !== null) {
                    $downLines[] = $this->renderCanonicalColumn($colDiff->actual, change: true);
                }
            }
        }

        // Drop columns (commented out for safety)
        foreach ($diff->columnDiffs as $colDiff) {
            if ($colDiff->action === 'drop') {
                $upLines[] = "// Column '{$colDiff->columnName}' exists in database but not in schema.";
                $upLines[] = '// Uncomment the line below to drop it. This is irreversible.';
                $upLines[] = "// \$table->dropColumn('{$colDiff->columnName}');";
            }
        }

        // Add indexes
        foreach ($diff->indexDiffs as $idxDiff) {
            if ($idxDiff->action === 'add') {
                if (count($idxDiff->columns) === 1) {
                    if (! $idxDiff->unique && ! $this->isColumnBeingAdded($idxDiff->columns[0], $diff)) {
                        $upLines[] = "\$table->index('{$idxDiff->columns[0]}');";
                        $downLines[] = "\$table->dropIndex(['{$idxDiff->columns[0]}']);";
                    }
                } else {
                    $cols = $this->renderArray($idxDiff->columns);
                    $method = $idxDiff->unique ? 'unique' : 'index';
                    $upLines[] = "\$table->{$method}({$cols});";
                    $downMethod = $idxDiff->unique ? 'dropUnique' : 'dropIndex';
                    $downLines[] = "\$table->{$downMethod}({$cols});";
                }
            }
        }

        // Drop indexes
        foreach ($diff->indexDiffs as $idxDiff) {
            if ($idxDiff->action === 'drop') {
                $cols = $this->renderArray($idxDiff->columns);
                $method = $idxDiff->unique ? 'dropUnique' : 'dropIndex';
                $upLines[] = "\$table->{$method}({$cols});";
            }
        }

        // Foreign keys
        foreach ($diff->fkDiffs as $fk) {
            if ($fk->action === 'add' && ! $fk->noConstraint) {
                $upLines[] = $this->renderForeignKey($fk);
                $downLines[] = "\$table->dropForeign(['{$fk->column}']);";
            }

            if ($fk->action === 'drop') {
                $upLines[] = "\$table->dropForeign(['{$fk->column}']);";
            }
        }

        // Timestamps
        if ($diff->addTimestamps) {
            $upLines[] = '$table->timestamps();';
            $downLines[] = '$table->dropTimestamps();';
        }

        if ($diff->dropTimestamps) {
            $upLines[] = '$table->dropTimestamps();';
            $downLines[] = '$table->timestamps();';
        }

        // Soft deletes
        if ($diff->addSoftDeletes) {
            $upLines[] = '$table->softDeletes();';
            $downLines[] = '$table->dropSoftDeletes();';
        }

        if ($diff->dropSoftDeletes) {
            $upLines[] = '$table->dropSoftDeletes();';
            $downLines[] = '$table->softDeletes();';
        }

        // Merge down lines: modify-reverts first, rename-reverts last
        $allDownLines = array_merge($downLines, $downRenameLines);

        $upBody = $this->indent($upLines, 3);
        $downBody = ! empty($allDownLines)
            ? $this->indent($allDownLines, 3)
            : '            //';

        return $this->wrapMigration(
            "Schema::table('{$diff->tableName}', function (Blueprint \$table) {\n{$upBody}\n        });",
            "Schema::table('{$diff->tableName}', function (Blueprint \$table) {\n{$downBody}\n        });",
        );
    }

    /**
     * Render an ID column using Blueprint shorthand.
     */
    private function renderIdColumn(CanonicalColumn $col): string
    {
        return match ($col->type) {
            'uuid' => $col->name === 'id' ? '$table->uuid(\'id\')->primary();' : "\$table->uuid('{$col->name}')->primary();",
            'ulid' => $col->name === 'id' ? '$table->ulid(\'id\')->primary();' : "\$table->ulid('{$col->name}')->primary();",
            default => $col->name === 'id' ? '$table->id();' : "\$table->id('{$col->name}');",
        };
    }

    /**
     * Render a full column definition from a CanonicalColumn.
     *
     * Used for both up() (desired state) and down() (actual state) migrations,
     * since both sides are now the same CanonicalColumn type.
     */
    private function renderCanonicalColumn(CanonicalColumn $col, bool $change = false): string
    {
        $method = ColumnTypeMap::toBlueprintMethod($col->type);
        $parts = ["\$table->{$method}('{$col->name}'"];

        // Add length for string types
        if ($col->length !== null && in_array($method, ['string', 'char'], true)) {
            $parts[0] = "\$table->{$method}('{$col->name}', {$col->length}";
        }

        // Add precision/scale for decimal
        if ($col->precision !== null && $method === 'decimal') {
            $scale = $col->scale ?? 2;
            $parts[0] = "\$table->{$method}('{$col->name}', {$col->precision}, {$scale}";
        }

        $chain = $parts[0].')';

        if ($col->unsigned && ! str_contains($method, 'unsigned') && ! str_contains($method, 'Unsigned')) {
            $chain .= '->unsigned()';
        }

        if ($col->nullable) {
            $chain .= '->nullable()';
        }

        if ($col->unique) {
            $chain .= '->unique()';
        }

        if ($col->index && ! $col->primary && ! $col->unique) {
            $chain .= '->index()';
        }

        if ($col->hasDefault) {
            $default = $this->renderDefaultValue($col->default);
            $chain .= "->default({$default})";
        }

        if ($col->expressionDefault !== null) {
            $chain .= "->default(DB::raw('{$col->expressionDefault}'))";
        }

        if ($change) {
            $chain .= '->change()';
        }

        return $chain.';';
    }

    /**
     * Render a foreign key constraint.
     */
    private function renderForeignKey(ForeignKeyDiff $fk): string
    {
        $chain = "\$table->foreign('{$fk->column}')->references('{$fk->foreignColumn}')->on('{$fk->foreignTable}')";

        if ($fk->onDelete !== null && strtolower($fk->onDelete) !== 'no action') {
            $chain .= "->onDelete('{$fk->onDelete}')";
        }

        if ($fk->onUpdate !== null && strtolower($fk->onUpdate) !== 'no action') {
            $chain .= "->onUpdate('{$fk->onUpdate}')";
        }

        return $chain.';';
    }

    /**
     * Render a PHP default value literal.
     */
    private function renderDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return "'".addslashes($value)."'";
        }

        return 'null';
    }

    /**
     * Render a PHP array literal for index column lists.
     */
    private function renderArray(array $items): string
    {
        $quoted = array_map(fn (string $item) => "'{$item}'", $items);

        return '['.implode(', ', $quoted).']';
    }

    /**
     * Wrap up() and down() bodies in the full migration file template.
     */
    private function wrapMigration(string $upBody, string $downBody): string
    {
        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        {$upBody}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        {$downBody}
    }
};

PHP;
    }

    /**
     * Indent an array of code lines.
     */
    private function indent(array $lines, int $level): string
    {
        $indent = str_repeat('    ', $level);

        return implode("\n", array_map(fn (string $line) => $indent.$line, $lines));
    }

    /**
     * Check if a column is being added in this diff (to avoid duplicate index creation).
     */
    private function isColumnBeingAdded(string $columnName, TableDiff $diff): bool
    {
        foreach ($diff->columnDiffs as $colDiff) {
            if ($colDiff->action === 'add' && $colDiff->columnName === $columnName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get column names managed by timestamps()/softDeletes() shorthand
     * that should be skipped when rendering individual column definitions.
     *
     * @return string[]
     */
    private function managedColumnNames(TableDiff $diff): array
    {
        $skip = [];

        if ($diff->addTimestamps) {
            $skip = array_merge($skip, self::TIMESTAMP_COLUMNS);
        }

        if ($diff->addSoftDeletes) {
            $skip = array_merge($skip, self::SOFT_DELETE_COLUMNS);
        }

        return $skip;
    }
}
