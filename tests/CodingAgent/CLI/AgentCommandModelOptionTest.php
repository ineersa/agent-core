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
 *  - combined with --resume without --prompt they are rejected (there is no
 *    request to ride and the session row owns the selection), and
 *  - without --prompt they build a draft-carrier request instead of being
 *    silently discarded.
 */
final class AgentCommandModelOptionTest extends TestCase
{
    #[Test]
    public function resumeWithModelOptionIsRejected(): void
    {
        $command = $this->commandWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be combined with --resume without --prompt');

        $command(output: new NullOutput(), resume: '48', model: 'llama_cpp/test');
    }

    #[Test]
    public function resumeWithReasoningOptionIsRejected(): void
    {
        $command = $this->commandWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be combined with --resume without --prompt');

        $command(output: new NullOutput(), resume: '48', reasoning: 'high');
    }

    #[Test]
    public function resumeWithPromptAndModelStaysUsable(): void
    {
        // With --prompt the options ride the StartRunRequest into the resumed
        // session's first run; only the no-prompt combination was the silent
        // drop, so it must stay allowed.
        $this->assertNoValidationThrow('hello', '48', 'llama_cpp/test', '');
        $this->assertNoValidationThrow('hello', '48', '', 'high');
    }

    #[Test]
    public function modelOnlyOptionsBuildDraftCarrierRequest(): void
    {
        // No --prompt: the request is a lazy draft carrier whose model rides
        // along until the first submit promotes the draft (mirrors /new --model).
        $request = $this->buildInitialRequest('', 'llama_cpp/test', 'high');

        $this->assertNotNull($request);
        $this->assertSame('', $request->prompt);
        $this->assertSame('llama_cpp/test', $request->model);
        $this->assertSame('high', $request->reasoning);
    }

    #[Test]
    public function promptAndModelBuildFullRequest(): void
    {
        $request = $this->buildInitialRequest('hello', 'llama_cpp/test', '');

        $this->assertNotNull($request);
        $this->assertSame('hello', $request->prompt);
        $this->assertSame('llama_cpp/test', $request->model);
        $this->assertNull($request->reasoning);
    }

    #[Test]
    public function noOptionsBuildNoRequest(): void
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
