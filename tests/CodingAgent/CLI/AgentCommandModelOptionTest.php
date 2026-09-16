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
 * Covers the --model/--reasoning option contract for TUI startup:
 *  - --resume without --prompt rejects them (session row owns selection),
 *  - --resume with --prompt keeps them usable on the initial request,
 *  - prompt-less launches without --resume do not reject at boot,
 *  - with --prompt they ride the initial StartRunRequest.
 */
final class AgentCommandModelOptionTest extends TestCase
{
    #[Test]
    public function resumeWithoutPromptAndModelIsRejected(): void
    {
        $command = $this->commandWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be combined with --resume without --prompt');

        $command(output: new NullOutput(), resume: '48', model: 'llama_cpp/test');
    }

    #[Test]
    public function resumeWithoutPromptAndReasoningIsRejected(): void
    {
        $command = $this->commandWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be combined with --resume without --prompt');

        $command(output: new NullOutput(), resume: '48', reasoning: 'high');
    }

    #[Test]
    public function resumeWithPromptAndModelStaysUsable(): void
    {
        $this->assertNoValidationThrow('hello', '48', 'llama_cpp/test', '');
        $this->assertNoValidationThrow('hello', '48', '', 'high');
    }

    #[Test]
    public function promptlessModelWithoutResumeStaysUsable(): void
    {
        // Not a silent resume drop: no request is built, and TUI/session
        // controls own selection after boot.
        $this->assertNoValidationThrow('', '', 'llama_cpp/test', '');
        $this->assertNoValidationThrow('', '', '', 'high');
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
        $this->assertNull($this->buildInitialRequest('', 'llama_cpp/test', 'high'));
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

    private function assertNoValidationThrow(string $prompt, string $resume, string $model, string $reasoning): void
    {
        $method = new \ReflectionMethod(AgentCommand::class, 'assertUsableModelOptions');

        try {
            $method->invoke(null, $prompt, $resume, $model, $reasoning);
        } catch (\InvalidArgumentException $e) {
            $this->fail(\sprintf('Options must stay usable, got: %s', $e->getMessage()));
        }

        $this->addToAssertionCount(1);
    }
}
