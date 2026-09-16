<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

/**
 * Thrown from the LLM HTTP progress hook when a run cancellation becomes visible
 * during a silent or in-flight stream wait.
 *
 * Transport layers may wrap this as a {@see \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface};
 * {@see LlmPlatformAdapter} unwraps the chain and treats it as an aborted stream.
 */
final class LlmStreamCancelledException extends \RuntimeException
{
}
