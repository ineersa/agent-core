<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Executes AI model invocations and returns structured provider responses.
 */
interface PlatformInterface
{
    /**
     * Executes a model request with input data and optional configuration parameters.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function invoke(string $model, array $input, array $options = []): array;
}
