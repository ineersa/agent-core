<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

/**
 * Ports Pi's parseCommandArgs() exactly.
 *
 * Behaviour:
 *  - Spaces, tabs, and newlines split unquoted arguments.
 *  - Single and double quotes group whitespace into a single argument.
 *  - Quote characters are consumed and not included in the argument.
 *  - Quotes do not nest; each quote type ends only on its own type.
 *  - Empty quoted pairs ("" or '') produce no argument.
 *  - Backslash is literal — no escaping.
 *  - An unclosed quote consumes the rest of the string and emits the
 *    argument if non-empty.
 *  - Unicode content is preserved.
 *
 * @internal
 */
final class PromptTemplateArgumentParser
{
    /**
     * Parse an argument string into a list of arguments.
     *
     * @return list<string>
     */
    public function parse(string $argsString): array
    {
        $args = [];
        $current = '';
        $inQuote = null;

        $chars = '' === $argsString ? [] : mb_str_split($argsString, 1, 'UTF-8');
        foreach ($chars as $char) {
            if (null !== $inQuote) {
                if ($char === $inQuote) {
                    $inQuote = null;
                } else {
                    $current .= $char;
                }
                continue;
            }

            if ('"' === $char || "'" === $char) {
                $inQuote = $char;
                continue;
            }

            if (1 === preg_match('/\s/u', $char)) {
                if ('' !== $current) {
                    $args[] = $current;
                    $current = '';
                }
                continue;
            }

            $current .= $char;
        }

        // Unclosed quote: emit the rest of the string if non-empty.
        if ('' !== $current) {
            $args[] = $current;
        }

        return $args;
    }
}
