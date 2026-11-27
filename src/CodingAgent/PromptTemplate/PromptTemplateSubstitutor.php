<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

/**
 * Substitutes placeholders in a prompt-template body with argument values.
 *
 * Substitution runs as sequential passes in this fixed order:
 *  1. Positional placeholders ($1, $2, ...)
 *  2. Slices (${@:N}, ${@:N:L})
 *  3. $ARGUMENTS
 *  4. $@
 *
 * A pass does not rescan its own replacements, but each later pass scans the
 * whole result of the earlier passes. An argument value that contains
 * placeholder syntax handled by a later pass (for example a $1 value containing
 * `$ARGUMENTS` or `${@:1}`, or any inserted value containing `$@`) is therefore
 * expanded by that later pass. Values inserted by the final $@ pass are not
 * rescanned.
 *
 * Edge behaviour:
 *  - $0 and out-of-range positional placeholders become empty strings.
 *  - $1.5 replaces $1 and leaves .5 literal.
 *  - $100 maps to args[99] or empty string if out of range.
 *  - ${@:0} clamps to the first argument (all args).
 *  - ${@:N:L} with zero length returns empty; length past end clamps.
 *  - $ARGUMENTS and $@ produce all args joined by a single space.
 *  - $ARGUMENTS_EXTRA replaces the $ARGUMENTS prefix and leaves _EXTRA.
 *  - ${@} without a colon and digit is not slice syntax (the regex requires
 *    ${@:N} or ${@:N:L}). It passes through unchanged because $@ is not a
 *    substring of ${@} (the { character sits between $ and @).
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
                if (isset($m[2])) {
                    $length = (int) $m[2];

                    return implode(' ', \array_slice($args, $start, $length));
                }

                return implode(' ', \array_slice($args, $start));
            },
            $content,
        );

        // 3 & 4. $ARGUMENTS, then $@ (the $@ pass also scans values inserted for $ARGUMENTS)
        $allArgs = implode(' ', $args);
        $content = str_replace('$ARGUMENTS', $allArgs, $content);
        $content = str_replace('$@', $allArgs, $content);

        return $content;
    }
}
