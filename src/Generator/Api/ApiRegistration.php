<?php

namespace SchemaCraft\Generator\Api;

use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generators\InlineTemplate;

/**
 * Canonical owner of the "register an action onto an API" code write.
 *
 * Everything that registers an action on an API's route file goes through here, so the
 * registration shape — `use {fqcn};` after the use block, and
 * `(new {Action}())->endpoint({Resource}::class);` inside the route group — lives in exactly
 * one place. Two surfaces, same private helpers, identical output:
 *
 *   • writesFor()  — declarative InlineTemplate[] a generator spreads into inlineTemplates();
 *                    the registration then flows through the generator's preview + run.
 *   • applyTo()    — imperative string transform for callers that already hold the route-file
 *                    content (GenerateController::importActions, the Import Actions panel).
 */
class ApiRegistration
{
    /** Anchor on the route group opener so we target *this* group's close, not an earlier closure's. */
    private const GROUP_ANCHOR = '->group(';

    private const GROUP_CLOSE = '});';

    /**
     * Declarative writes that register an action onto one or more APIs' route files.
     *
     * @param  array<string, string>  $apiResources  API name => resource FQCN used for ->endpoint()
     * @param  string  $actionClass  the Action FQCN being registered
     * @return InlineTemplate[]
     */
    public static function writesFor(array $apiResources, string $actionClass): array
    {
        $writes = [];

        foreach ($apiResources as $apiName => $resourceFqcn) {
            $routeFile = ConfigResolver::resolve($apiName)->routeFile;

            // Imports after the first existing `use` (order is irrelevant; the runner dup-detects
            // on the trimmed snippet). Leading "\n" mirrors the imperative ApiFileWriter::addImport.
            foreach (self::importFqcns($actionClass, $resourceFqcn) as $fqcn) {
                $writes[] = InlineTemplate::raw("\nuse {$fqcn};")
                    ->into($routeFile)->afterRegex('/^use .+;$/m');
            }

            $writes[] = InlineTemplate::raw(self::endpointLine($actionClass, $resourceFqcn)."\n")
                ->into($routeFile)->anchor(self::GROUP_ANCHOR)->before(self::GROUP_CLOSE);
        }

        return $writes;
    }

    /**
     * Imperative twin of writesFor() for callers that already hold route-file content. Same
     * imports, same endpoint line, same insertion positions. Returns the (possibly) modified
     * content; a no-op when the action is already registered.
     */
    public static function applyTo(string $routeContent, string $actionClass, string $resourceFqcn): string
    {
        if (self::isRegistered($routeContent, $actionClass)) {
            return $routeContent;
        }

        $writer = new ApiFileWriter;
        foreach (self::importFqcns($actionClass, $resourceFqcn) as $fqcn) {
            $routeContent = $writer->addImport($routeContent, $fqcn);
        }

        return self::insertBeforeGroupClose($routeContent, self::endpointLine($actionClass, $resourceFqcn));
    }

    /**
     * Whether the action is already registered on this route content, with any resource.
     * Matches on the action's endpoint call so re-importing or re-generating is idempotent.
     */
    public static function isRegistered(string $routeContent, string $actionClass): bool
    {
        return str_contains($routeContent, '(new '.class_basename($actionClass).'())->endpoint(');
    }

    /**
     * FQCNs that must be imported for one registration. Both the action and its resource are
     * referenced by short name in the endpoint line, so both need a `use`.
     *
     * @return string[]
     */
    private static function importFqcns(string $actionClass, string $resourceFqcn): array
    {
        return [$actionClass, $resourceFqcn];
    }

    /** The single registration statement; 4-space indented to sit inside the route group body. */
    private static function endpointLine(string $actionClass, string $resourceFqcn): string
    {
        return '    (new '.class_basename($actionClass).'())->endpoint('.class_basename($resourceFqcn).'::class);';
    }

    /** Insert a line inside the route group, before its closing `});`. Mirrors writesFor()'s anchor. */
    private static function insertBeforeGroupClose(string $content, string $line): string
    {
        $groupPos = strpos($content, self::GROUP_ANCHOR);
        $searchFrom = $groupPos === false ? 0 : $groupPos + strlen(self::GROUP_ANCHOR);

        $closePos = strpos($content, self::GROUP_CLOSE, $searchFrom);
        if ($closePos === false) {
            return $content;
        }

        return substr($content, 0, $closePos).$line."\n".substr($content, $closePos);
    }
}
