<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Tool\ProviderRequest;

/**
 * Defines a hook interface for intercepting provider requests before they are dispatched. It allows modifying or canceling the request based on the model, input, and options provided.
 */
interface BeforeProviderRequestHookInterface
{
    /**
     * Intercepts provider request creation to allow modification or cancellation.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     */
    public function beforeProviderRequest(
        string $model,
        array $input,
        array $options,
        ?CancellationTokenInterface $cancelToken = null,
    ): ?ProviderRequest;
}
