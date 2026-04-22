<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Tool\ProviderRequest;

/**
 * Intercepts provider requests before dispatch, allowing modification or cancellation.
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
