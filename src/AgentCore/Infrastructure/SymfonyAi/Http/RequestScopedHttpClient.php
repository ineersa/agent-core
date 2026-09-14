<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi\Http;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * HttpClient decorator that merges nested request-option overrides onto each request.
 *
 * Symfony AI Completions/OpenResponses model clients put provider options in the JSON body
 * and do not forward HttpClient transport keys such as max_duration. This decorator is the
 * host seam for per-call transport budgets without raising the shared client default.
 *
 * Option stacks are fiber-local, matching {@see \Ineersa\AgentCore\Infrastructure\RunLogContext}.
 */
final class RequestScopedHttpClient implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait;

    /**
     * @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, list<array<string, mixed>>>|null
     */
    private static ?\WeakMap $fiberStacks = null;

    /** @var list<array<string, mixed>> */
    private static array $defaultStack = [];

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create();
    }

    /**
     * @param array<string, mixed> $options Symfony HttpClient options merged for the duration of $callback
     */
    public static function runWithOptions(array $options, callable $callback): mixed
    {
        $stack = self::readStack();
        $stack[] = $options;
        self::writeStack($stack);
        try {
            return $callback();
        } finally {
            $stack = self::readStack();
            if ([] !== $stack) {
                array_pop($stack);
                self::writeStack($stack);
            }
        }
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        /** @var array<string, mixed> $merged */
        $merged = $this->mergeScopedOptions($options);

        return $this->client->request($method, $url, $merged);
    }

    public function reset(): void
    {
        self::$fiberStacks = null;
        self::$defaultStack = [];
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function mergeScopedOptions(array $options): array
    {
        foreach (self::readStack() as $scoped) {
            $options = array_replace($options, $scoped);
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readStack(): array
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            return self::$defaultStack;
        }
        if (null === self::$fiberStacks || !self::$fiberStacks->offsetExists($fiber)) {
            return [];
        }

        return self::$fiberStacks[$fiber];
    }

    /**
     * @param list<array<string, mixed>> $stack
     */
    private static function writeStack(array $stack): void
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            self::$defaultStack = $stack;

            return;
        }
        self::$fiberStacks ??= new \WeakMap();
        self::$fiberStacks->offsetSet($fiber, $stack);
    }
}
