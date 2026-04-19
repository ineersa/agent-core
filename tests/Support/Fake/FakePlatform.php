<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Fake;

use Ineersa\AgentCore\Contract\Tool\PlatformInterface;

final class FakePlatform implements PlatformInterface
{
    /** @var list<array<string, mixed>|\Throwable> */
    private array $responses;

    /** @var list<array{model: string, input: array<string, mixed>, options: array<string, mixed>}> */
    public array $invocations = [];

    /**
     * @param list<array<string, mixed>|\Throwable> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = array_values($responses);
    }

    public function push(array|\Throwable $response): void
    {
        $this->responses[] = $response;
    }

    public function invoke(string $model, array $input, array $options = []): array
    {
        $this->invocations[] = [
            'model' => $model,
            'input' => $input,
            'options' => $options,
        ];

        if ([] === $this->responses) {
            return [
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'fake-platform-default',
                    ]],
                ],
                'usage' => [],
                'stop_reason' => 'stop',
                'error' => null,
            ];
        }

        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
