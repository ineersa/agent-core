<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Minimal stderr logger for the package console process.
 *
 * Avoids a production NullLogger while keeping the package free of MonologBundle.
 */
final class OmStderrLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $levelString = \is_string($level) ? $level : LogLevel::INFO;
        $line = \sprintf('[%s] %s', strtoupper($levelString), (string) $message);
        if ([] !== $context) {
            $safe = [];
            foreach ($context as $key => $value) {
                if (!\is_string($key)) {
                    continue;
                }
                // Structured correlation fields only — never dump arbitrary payloads.
                if (\in_array($key, ['component', 'event_type', 'session_id', 'run_id', 'pid', 'parent_pid', 'exception_class', 'version', 'status', 'observation_count', 'exit_code', 'console'], true)) {
                    $safe[$key] = \is_scalar($value) || null === $value ? $value : $value::class;
                }
            }
            if ([] !== $safe) {
                $line .= ' '.json_encode($safe, \JSON_UNESCAPED_SLASHES);
            }
        }

        fwrite(\STDERR, $line.\PHP_EOL);
    }
}
