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

        $chars = $this->splitChars($argsString);
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

            if ($this->isWhitespace($char)) {
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

    /**
     * Split a string into characters preserving multi-byte UTF-8.
     *
     * @return list<string>
     */
    private function splitChars(string $s): array
    {
        if ('' === $s) {
            return [];
        }

        $result = preg_split('//u', $s, -1, \PREG_SPLIT_NO_EMPTY);

        return false !== $result ? $result : [];
    }

    private function isWhitespace(string $char): bool
    {
        return 1 === preg_match('/\s/u', $char);
    }
}
