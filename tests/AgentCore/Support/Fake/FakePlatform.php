<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Fake;

use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;

final class FakePlatform implements PlatformInterface
{
    /** @var list<ModelInvocationRequest> */
    public array $invocations = [];
    /** @var list<PlatformInvocationResult|\Throwable> */
    private array $responses;

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
                assistantMessage: new AssistantMessage(new Text('fake-platform-default')),
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
