<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use SchemaCraft\Writer\RelationshipParser;
use SchemaCraft\Writer\SchemaWriter;

class RelationshipCommand extends Command
{
    protected $signature = 'schema-craft:relationship
        {definition : Relationship definition e.g. "User->belongsTo(Account)->hasMany(User)"}';

    protected $description = 'Add a relationship to SchemaCraft schema files';

    protected $aliases = ['schema-craft:rel'];

    public function handle(Filesystem $files): int
    {
        if (! App::environment('local')) {
            $this->components->error('This command can only be run in the local environment.');

            return self::FAILURE;
        }

        $definition = $this->argument('definition');
        $parser = new RelationshipParser;

        try {
            $instructions = $parser->parse($definition);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $writer = new SchemaWriter($files);
        $hasError = false;

        foreach ($instructions as $instruction) {
            $schemaPath = app_path("Schemas/{$instruction->schemaName}Schema.php");

            if (! $files->exists($schemaPath)) {
                $this->components->error("Schema file not found: {$schemaPath}");
                $hasError = true;

                continue;
            }

            $modelFqcn = "App\\Models\\{$instruction->relatedModelName}";

            $result = $writer->addRelationship(
                schemaFilePath: $schemaPath,
                relationshipType: $instruction->relationshipType,
                relatedModelFqcn: $modelFqcn,
                morphName: $instruction->morphName,
                propertyName: $instruction->propertyName,
            );

            if ($result->success) {
                $this->components->info($result->message);
            } else {
                $this->components->warn($result->message);
            }
        }

        return $hasError ? self::FAILURE : self::SUCCESS;
    }
}
