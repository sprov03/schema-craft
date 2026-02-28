<?php

namespace SchemaCraft\Generator\Filament;

use SchemaCraft\Generator\Api\GeneratedFile;

/**
 * Generates a Laravel policy class with Filament-expected authorization methods.
 */
class FilamentPolicyGenerator
{
    public function __construct(
        private string $stubsPath,
    ) {}

    public function generate(
        string $modelName,
        string $modelNamespace = 'App\\Models',
        string $policyNamespace = 'App\\Policies',
        string $userModel = 'App\\Models\\User',
    ): GeneratedFile {
        $stub = file_get_contents($this->stubsPath.'/filament/policy.stub');

        $modelVariable = lcfirst($modelName);
        $userClass = class_basename($userModel);

        $content = str_replace(
            [
                '{{ policyNamespace }}',
                '{{ modelFqcn }}',
                '{{ userModel }}',
                '{{ policyClass }}',
                '{{ userClass }}',
                '{{ modelClass }}',
                '{{ modelVariable }}',
            ],
            [
                $policyNamespace,
                $modelNamespace.'\\'.$modelName,
                $userModel,
                $modelName.'Policy',
                $userClass,
                $modelName,
                $modelVariable,
            ],
            $stub,
        );

        return new GeneratedFile(
            path: $this->namespaceToPath($policyNamespace, $modelName.'Policy'),
            content: $this->cleanOutput($content),
        );
    }

    private function namespaceToPath(string $namespace, string $className): string
    {
        $relativePath = str_replace('\\', '/', $namespace);

        if (str_starts_with($relativePath, 'App/')) {
            $relativePath = 'app/'.substr($relativePath, 4);
        }

        return $relativePath.'/'.$className.'.php';
    }

    private function cleanOutput(string $content): string
    {
        return preg_replace('/\n{3,}/', "\n\n", $content);
    }
}
