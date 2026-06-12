<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

/**
 * Substitutes placeholders in a prompt-template body with argument values.
 *
 * Substitution order (important — prevents later passes from corrupting
 * structured placeholder syntax):
 *  1. Positional placeholders ($1, $2, ...)
 *  2. Slices (${@:N}, ${@:N:L})
 *  3. $ARGUMENTS
 *  4. $@
 *
 * No recursive substitution: argument values inserted into the result are
 * never scanned for further placeholders.
 *
 * Edge behaviour:
 *  - $0 and out-of-range positional placeholders become empty strings.
 *  - $1.5 replaces $1 and leaves .5 literal.
 *  - $100 maps to args[99] or empty string if out of range.
 *  - ${@:0} clamps to the first argument (all args).
 *  - ${@:N:L} with zero length returns empty; length past end clamps.
 *  - $ARGUMENTS and $@ produce all args joined by a single space.
 *  - $ARGUMENTS_EXTRA replaces the $ARGUMENTS prefix and leaves _EXTRA.
 *  - Case-sensitive: $arguments is NOT replaced.
 *  - Backslash is literal and does not escape placeholders.
 *
 * @internal
 */
final class PromptTemplateSubstitutor
{
    /**
     * Substitute placeholders in the template content with argument values.
     *
     * @param list<string> $args
     */
    public function substitute(string $content, array $args): string
    {
        // 1. Positional placeholders $1, $2, ...
        $content = preg_replace_callback('/\$(\d+)/', static function (array $m) use ($args): string {
            $idx = ((int) $m[1]) - 1;
            if ($idx < 0 || $idx >= \count($args)) {
                return '';
            }

            return $args[$idx];
        }, $content);

        // 2. Slices ${@:N} and ${@:N:L}
        $content = preg_replace_callback(
            '/\$\{@:(\d+)(?::(\d+))?\}/',
            static function (array $m) use ($args): string {
                $start = ((int) $m[1]) - 1;
                if ($start < 0) {
                    $start = 0;
                }
                if (isset($m[2]) && '' !== $m[2]) {
                    $length = (int) $m[2];

                    return implode(' ', \array_slice($args, $start, $length));
                }

                return implode(' ', \array_slice($args, $start));
            },
            $content,
        );

        // 3 & 4. $ARGUMENTS, then $@ (order matters: $@ is a substring of $ARGUMENTS)
        $allArgs = implode(' ', $args);
        $content = str_replace('$ARGUMENTS', $allArgs, $content);
        $content = str_replace('$@', $allArgs, $content);

        return $content;
    }
}
