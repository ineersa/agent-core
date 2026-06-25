<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Bash tool settings resolved from Hatfield config.
 *
 * Immutable value object. Contains timeout, background prompt threshold,
 * poll interval, and log tail character cap for bash tool execution.
 *
 * Hydrated from the tools.bash section of Hatfield merged config
 * via Symfony Serializer.
 */
final readonly class BashToolConfig
{
    /**
     * @param int $defaultTimeoutSeconds            Default timeout for bash commands (seconds)
     * @param int $maxTimeoutSeconds                Upper bound on model-supplied timeout (seconds)
     * @param int $backgroundPromptThresholdSeconds Seconds before asking to move to background
     * @param int $pollIntervalMicros               Poll interval in microseconds for supervision loop
     * @param int $logTailChars                     Max chars to read from background process log
     */
    public function __construct(
        #[SerializedName('default_timeout_seconds')]
        public int $defaultTimeoutSeconds = 300,

        #[SerializedName('max_timeout_seconds')]
        public int $maxTimeoutSeconds = 3600,

        #[SerializedName('background_prompt_threshold_seconds')]
        public int $backgroundPromptThresholdSeconds = 15,

        #[SerializedName('poll_interval_micros')]
        public int $pollIntervalMicros = 100_000,

        #[SerializedName('log_tail_chars')]
        public int $logTailChars = 20000,
    ) {
    }

    /**
     * DI factory — extract bash tool settings from AppConfig entity.
     *
     * Used by the Symfony container via services.yaml factory definition
     * so that autowired consumers receive the same instance that lives
     * inside AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->tools->bash;
    }
}
