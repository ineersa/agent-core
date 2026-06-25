<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\CastorLlmMode;

final class CastorCommandRewriter
{
    public const string CASTOR_COMMAND_PATTERN = '/(^|\s)(?:vendor\/bin\/)?castor(?=\s|$)/';

    public const string LLM_MODE_PATTERN = '/\bLLM_MODE\s*=/';

    public const string VERSION_CHECK_PATTERN = '/\bCASTOR_DISABLE_VERSION_CHECK\s*=/';

    public const string NO_COLOR_PATTERN = '/\bNO_COLOR\s*=/';

    public const string CL_COLOR_PATTERN = '/\bCLICOLOR\s*=/';

    public const string CASTOR_LIST_PATTERN = '/((?:^|\s|&&\s*|\|\|\s*|;\s*)(?:vendor\/bin\/)?castor\s+list\b)/';

    public function isCastorCommand(string $command): bool
    {
        return 1 === preg_match(self::CASTOR_COMMAND_PATTERN, $command);
    }

    public function rewrite(string $command): string
    {
        $exports = [];
        if (1 !== preg_match(self::LLM_MODE_PATTERN, $command)) {
            $exports[] = 'export LLM_MODE=true';
        }
        if (1 !== preg_match(self::VERSION_CHECK_PATTERN, $command)) {
            $exports[] = 'export CASTOR_DISABLE_VERSION_CHECK=1';
        }
        if (1 !== preg_match(self::NO_COLOR_PATTERN, $command)) {
            $exports[] = 'export NO_COLOR=1';
        }
        if (1 !== preg_match(self::CL_COLOR_PATTERN, $command)) {
            $exports[] = 'export CLICOLOR=0';
        }

        // preg_replace returns string|null; null cannot happen with this constant pattern — guard for phpstan.
        $transformed = preg_replace(self::CASTOR_LIST_PATTERN, '$1 --format=md --short --no-ansi', $command) ?? $command;

        if ([] === $exports) {
            return $transformed;
        }

        return implode("\n", $exports)."\n".$transformed;
    }
}
