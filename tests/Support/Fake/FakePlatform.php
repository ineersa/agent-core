<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Fake;

use Ineersa\AgentCore\Contract\Tool\PlatformInterface;
use Ineersa\AgentCore\Domain\Tool\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Tool\PlatformInvocationResult;
use Symfony\AI\Platform\Message\AssistantMessage;

final class FakePlatform implements PlatformInterface
{
    /** @var list<PlatformInvocationResult|\Throwable> */
    private array $responses;

    /** @var list<ModelInvocationRequest> */
    public array $invocations = [];

    /**
     * @param list<PlatformInvocationResult|\Throwable> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = array_values($responses);
    }

    public function push(PlatformInvocationResult|\Throwable $response): void
    {
        $this->responses[] = $response;
    }

    public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
    {
        $this->invocations[] = $request;

        if ([] === $this->responses) {
            return new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(content: 'fake-platform-default'),
                usage: [],
                stopReason: 'stop',
                error: null,
            );
        }

        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
