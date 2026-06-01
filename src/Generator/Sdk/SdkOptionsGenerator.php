<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * Generates a companion options class for a closed-enumeration column type — bitmask flags or
 * native PHP enum cases. Emitted alongside the DTOs so consumers get a typed reference to the
 * allowed values:
 *
 *   $client->update(['permissions' => MissionBitmaskOptions::LOAN | MissionBitmaskOptions::PURCHASE]);
 *   $post->status = PostStatusOptions::PUBLISHED;
 *
 * Wire shape is unchanged — the DTO field stays scalar/array. The companion is additive metadata
 * so consumers can autocomplete and type-check their values instead of writing magic strings or
 * bare integers.
 *
 * Why constants instead of a generated PHP backed enum (for the enum case): keeps both kinds of
 * companions uniform in shape, and bitmask flags can't be real enum cases anyway (they OR-combine
 * into composite ints that aren't valid enum values). One companion shape, both option kinds.
 */
class SdkOptionsGenerator
{
    /**
     * @param  array{kind: string, values: array<int, array{value: mixed, name?: string, label: string, description?: string}>}  $options
     */
    public function generate(string $companionClassName, array $options, string $namespace): string
    {
        $constLines = [];

        foreach ($options['values'] as $opt) {
            $constName = $opt['name'] ?? $this->fallbackConstName($opt);
            $literal = $this->scalarLiteral($opt['value']);

            if (! empty($opt['description'])) {
                $constLines[] = '    /** '.$this->escapeComment($opt['description']).' */';
            } elseif (! empty($opt['label']) && $opt['label'] !== $constName) {
                $constLines[] = '    /** '.$this->escapeComment($opt['label']).' */';
            }

            $constLines[] = "    public const {$constName} = {$literal};";
            $constLines[] = '';
        }

        // Drop the trailing blank line — keeps the closing brace tight.
        if (! empty($constLines) && end($constLines) === '') {
            array_pop($constLines);
        }

        $kindLine = $options['kind'] === 'bitmask'
            ? 'Bitmask flags — combine with bitwise OR. Use the related Data wire shape (`value`/`flags`) for I/O.'
            : 'Enum values — exactly one applies. Use the constant directly as the field value.';

        $body = implode("\n", $constLines);

        return <<<PHP
<?php

namespace {$namespace};

/**
 * {$kindLine}
 *
 * Generated companion — do not edit by hand. Source of truth lives in the upstream
 * type definition (Bitmask subclass or PHP enum), and this file is rewritten on every
 * SDK regeneration.
 */
final class {$companionClassName}
{
{$body}
}

PHP;
    }

    private function scalarLiteral(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        // Strings — single-quote and escape backslashes + single quotes. Enum backing values
        // ship through here; they're almost always simple identifiers, but a careful escape
        // covers anything unusual.
        $escaped = strtr((string) $value, ['\\' => '\\\\', "'" => "\\'"]);

        return "'{$escaped}'";
    }

    /**
     * Fallback when an option entry lacks an explicit `name` — derive a PHP-safe constant
     * identifier from the option's value or label. Should never trigger for bitmask /
     * BackedEnum sources (both always emit `name`), but kept defensive so unusual sources
     * don't crash generation.
     */
    private function fallbackConstName(array $opt): string
    {
        $candidate = (string) ($opt['label'] ?? $opt['value'] ?? '');
        $upper = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $candidate));

        return preg_match('/^[A-Z_]/', $upper) ? $upper : '_'.$upper;
    }

    private function escapeComment(string $text): string
    {
        return strtr($text, ['*/' => '* /']);
    }
}
