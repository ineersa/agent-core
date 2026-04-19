<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Defines the contract for executing AI model invocations within the Agent Core platform. It standardizes the interface for sending input payloads and receiving structured responses from various model providers. This abstraction allows the system to interact with different backend services through a unified method signature.
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
