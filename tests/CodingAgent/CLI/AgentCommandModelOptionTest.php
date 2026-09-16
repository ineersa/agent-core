<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\CLI\AgentCommand;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @covers \Ineersa\CodingAgent\CLI\AgentCommand
 *
 * Covers the --model/--reasoning option contract for TUI startup: without
 * --prompt they are rejected up front (the session row owns model selection
 * and no prompt-less request exists to carry them); with --prompt they ride
 * the initial StartRunRequest.
 */
final class AgentCommandModelOptionTest extends TestCase
{
    #[Test]
    public function modelWithoutPromptIsRejected(): void
    {
        $command = $this->commandWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require --prompt');

        $command(output: new NullOutput(), model: 'llama_cpp/test');
    }

    #[Test]
    public function reasoningWithoutPromptIsRejected(): void
    {
        $command = $this->commandWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require --prompt');

        $command(output: new NullOutput(), resume: '48', reasoning: 'high');
    }

    #[Test]
    public function promptWithModelBuildsFullRequest(): void
    {
        $request = $this->buildInitialRequest('hello', 'llama_cpp/test', '');

        $this->assertNotNull($request);
        $this->assertSame('hello', $request->prompt);
        $this->assertSame('llama_cpp/test', $request->model);
        $this->assertNull($request->reasoning);
    }

    #[Test]
    public function promptWithoutOptionsBuildsPlainRequest(): void
    {
        $request = $this->buildInitialRequest('hello', '', '');

        $this->assertNotNull($request);
        $this->assertSame('hello', $request->prompt);
        $this->assertNull($request->model);
        $this->assertNull($request->reasoning);
    }

    #[Test]
    public function noPromptBuildsNoRequest(): void
    {
        $this->assertNull($this->buildInitialRequest('', '', ''));
    }

    /**
     * The validation under test runs before any constructor service is used,
     * so an uninitialized instance is sufficient for the rejection paths.
     */
    private function commandWithoutConstructor(): AgentCommand
    {
        return (new \ReflectionClass(AgentCommand::class))
            ->newInstanceWithoutConstructor();
    }

    private function buildInitialRequest(string $prompt, string $model, string $reasoning): ?StartRunRequest
    {
        $method = new \ReflectionMethod(AgentCommand::class, 'buildInitialRequest');

        return $method->invoke(null, $prompt, $model, $reasoning);
    }
}
