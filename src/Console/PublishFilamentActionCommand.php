<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Action;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\Api\ApiFileWriter;
use SchemaCraft\Generator\Filament\FilamentActionCodeGenerator;
use SchemaCraft\Scanner\ActionScanner;

class PublishFilamentActionCommand extends Command
{
    protected $signature = 'schema:publish-filament-action
        {action : The Action class (e.g., SssssContactAction or App\\Models\\Actions\\Contact\\SssssContactAction)}
        {--force : Overwrite existing filamentAction() method}
        {--preview : Preview the generated code without writing}';

    protected $description = 'Publish an explicit filamentAction() override into an Action class for customization';

    public function handle(Filesystem $files): int
    {
        $actionClass = $this->resolveActionClass($this->argument('action'));

        if (! class_exists($actionClass)) {
            $this->components->error("Action class [{$actionClass}] not found.");

            return self::FAILURE;
        }

        if (! is_subclass_of($actionClass, Action::class)) {
            $this->components->error("[{$actionClass}] is not an Action class.");

            return self::FAILURE;
        }

        $scanner = new ActionScanner($actionClass);
        $definition = $scanner->scan();

        $generator = new FilamentActionCodeGenerator;
        $methodCode = $generator->generate($definition);
        $requiredImports = $generator->requiredImports($definition);

        if ($this->option('preview')) {
            $this->components->info('Generated filamentAction() method:');
            $this->line('');
            $this->line($methodCode);
            $this->line('');
            $this->components->info('Required imports:');
            foreach ($requiredImports as $import) {
                $this->line("  use {$import};");
            }

            return self::SUCCESS;
        }

        // Find the action file
        $ref = new \ReflectionClass($actionClass);
        $filePath = $ref->getFileName();

        if (! $filePath || ! $files->exists($filePath)) {
            $this->components->error("Cannot locate file for [{$actionClass}].");

            return self::FAILURE;
        }

        $content = $files->get($filePath);

        // Check if filamentAction() already exists
        if (str_contains($content, 'function filamentAction(') && ! $this->option('force')) {
            $this->components->warn('filamentAction() already exists. Use --force to overwrite.');

            return self::FAILURE;
        }

        // If force and method exists, replace it
        if (str_contains($content, 'function filamentAction(') && $this->option('force')) {
            $content = $this->replaceMethod($content, 'filamentAction', $methodCode);
        } else {
            // Insert before the closing brace of the class
            $content = $this->insertBeforeLastBrace($content, $methodCode);
        }

        // Add required imports
        $writer = new ApiFileWriter;
        foreach ($requiredImports as $import) {
            $content = $writer->addImport($content, $import);
        }

        $files->put($filePath, $content);

        $this->components->info("Published filamentAction() to [{$filePath}].");
        $this->components->info('You can now customize the form components, labels, and behavior.');

        return self::SUCCESS;
    }

    private function resolveActionClass(string $input): string
    {
        if (str_contains($input, '\\')) {
            return $input;
        }

        if (! str_ends_with($input, 'Action')) {
            $input .= 'Action';
        }

        // Search all configured model namespaces for the action
        foreach (ConfigResolver::allConnectionNames() as $name) {
            $config = ConfigResolver::resolveConnection($name);
            $actionsDir = base_path($config->actionDirectory());

            if (! is_dir($actionsDir)) {
                continue;
            }

            $dirs = glob($actionsDir.'/*', GLOB_ONLYDIR);

            foreach ($dirs as $dir) {
                $modelName = basename($dir);
                $fqcn = $config->actionNamespaceForModel($modelName).'\\'.$input;

                if (class_exists($fqcn)) {
                    return $fqcn;
                }
            }
        }

        return $input;
    }

    private function insertBeforeLastBrace(string $content, string $method): string
    {
        $lastBrace = strrpos($content, '}');

        if ($lastBrace === false) {
            return $content;
        }

        return substr($content, 0, $lastBrace)."\n".$method."\n}\n";
    }

    private function replaceMethod(string $content, string $methodName, string $newMethod): string
    {
        $pattern = '/(\n\s*)((?:\/\*\*[\s\S]*?\*\/\s*)?(?:public|protected|private)\s+(?:static\s+)?function\s+'.$methodName.'\s*\()/';

        if (! preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
            return $this->insertBeforeLastBrace($content, $newMethod);
        }

        $methodStart = $match[2][1];

        $braceDepth = 0;
        $inMethod = false;
        $methodEnd = $methodStart;

        for ($i = $methodStart; $i < strlen($content); $i++) {
            if ($content[$i] === '{') {
                $braceDepth++;
                $inMethod = true;
            } elseif ($content[$i] === '}') {
                $braceDepth--;
                if ($inMethod && $braceDepth === 0) {
                    $methodEnd = $i + 1;

                    break;
                }
            }
        }

        return substr($content, 0, $methodStart).$newMethod.substr($content, $methodEnd);
    }
}
