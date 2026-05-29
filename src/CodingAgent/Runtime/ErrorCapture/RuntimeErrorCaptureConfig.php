<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ErrorCapture;

/**
 * Configuration for HATFIELD_CAPTURE_ERRORS env-driven error policy.
 *
 * When enabled (default, HATFIELD_CAPTURE_ERRORS=1), uncaught exceptions
 * at top-level boundaries are converted into user-visible runtime/TUI
 * failures rather than crashing the process.
 *
 * When disabled (HATFIELD_CAPTURE_ERRORS=0), exceptions propagate
 * normally so test/CI harnesses see hard failures.
 *
 * The env value is supplied by Symfony DI via services.yaml
 * (%env(string:HATFIELD_CAPTURE_ERRORS)%, defaulted to '1' in
 * container parameters). The object never reads $_SERVER/$_ENV
 * directly — that is the container's responsibility.
 *
 * Usage: inject this config at the few top-level callback boundaries
 * (HeadlessController Revolt callbacks, CancelListener, RuntimeEventPoller).
 * Most code should NOT inject this — it should throw or return typed results,
 * and let the Symfony ConsoleEvents::ERROR subscriber or the top-level
 * callback wrapper decide capture vs crash.
 */
final class RuntimeErrorCaptureConfig
{
    public readonly bool $captureErrors;

    /**
     * @param string $envValue Container-provided value from %env(string:HATFIELD_CAPTURE_ERRORS)%.
     *                         Defaults to '1' as a safe fallback for direct instantiation (tests).
     */
    public function __construct(
        string $envValue = '1',
    ) {
        $this->captureErrors = '1' === $envValue;
    }
}
