<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Extension\ExtensionToolInvoker;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class ExtensionToolInvokerTest extends IsolatedKernelTestCase
{
    public function testNativeBudgetRetainedOutputsAndStickyCancellationSurviveProcessingFailure(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('standalone native tools');
        $projection = $container->get(RunOperationalProjectionRepository::class);
        $projection->replace(new RunState($run, RunStatus::Completed));
        $contexts = $container->get(StackToolExecutionContextAccessor::class);
        $payloads = $container->get(ToolPayloadStoreInterface::class);
        $toolSets = $container->get(ToolSetResolverInterface::class);
        $makeInvoker = static fn (ToolExecutorInterface $executor): ExtensionToolInvoker => new ExtensionToolInvoker(
            static fn () => $executor, $contexts, $projection, $container->get(EventStoreInterface::class),
            $container->get(ToolExecutionResultStore::class), $payloads, $container->get(DenormalizerInterface::class), $run, $toolSets,
        );
        $invoker = $makeInvoker($container->get(ToolExecutorInterface::class));
        $api = $container->get(ExtensionApiInterface::class);
        $handler = new class($projection) implements ContextualExtensionToolHandlerInterface {
            public ?int $budget = null;
            public bool $observedCancellation = false;

            public function __construct(private RunOperationalProjectionRepository $projection)
            {
            }

            public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
            {
                $this->budget = $context->timeoutSeconds;
                if ($arguments['cancel'] ?? false) {
                    $this->projection->replace(new RunState($context->runId, RunStatus::Cancelling));
                    $this->observedCancellation = $context->cancellationToken?->isCancellationRequested() ?? false;
                    $this->projection->replace(new RunState($context->runId, RunStatus::Completed));
                }

                return 'native handler returned';
            }
        };
        $api->registerTool(new ToolRegistrationDTO('native_probe', 'Observe native context', ['type' => 'object'], $handler, timeoutSeconds: 10));
        $budget = $invoker->invoke(new ToolInvocationRequestDTO($run, 'budget', 'native_probe', []));
        $this->assertFalse($budget->isError);
        $this->assertSame(10, $handler->budget);

        $path = $this->isolatedCwd().'/large.txt';
        file_put_contents($path, str_repeat('x', 1_200_000)."\n");
        $direct = $invoker->invoke(new ToolInvocationRequestDTO($run, 'direct', 'read', ['path' => $path], true));
        $this->assertFalse($direct->isError, $direct->text);
        $this->assertCount(1, $direct->outputs, 'Native capture and processor must not retain duplicate copies.');
        $this->assertStringContainsString($direct->outputs[0]->path, $direct->text);
        $nested = $invoker->invoke(new ToolInvocationRequestDTO($run, 'outer', 'code_mode', ['script' => \sprintf(
            'return [tool("read", ["path" => %s]), tool("bash", ["command" => "printf %%030000d 0"])];', var_export($path, true),
        )], true));
        $this->assertFalse($nested->isError, $nested->text);
        $this->assertCount(2, $nested->outputs, 'Nested read and Bash artifacts belong to the outer invocation.');
        $sizes = array_column($nested->outputs, 'byteCount');
        sort($sizes);
        $this->assertSame([30_000, 1_200_001], $sizes);
        $container->get(OutputCap::class)->cleanupRun($run, 'test-retained-output');
        foreach ([$direct, $nested] as $result) {
            foreach ($result->outputs as $reference) {
                $this->assertFileExists($reference->path);
                $this->assertStringContainsString('tool-attachments', $reference->path);
                $this->assertSame($reference->byteCount, filesize($reference->path));
                $this->assertStringContainsString($reference->path, $result->text);
                if ($reference->byteCount > 1_000_000) {
                    $this->assertSame(hash_file('sha256', $path), hash_file('sha256', $reference->path));
                }
            }
        }

        $processor = new class implements ToolResultProcessorInterface {
            public function process(ToolResult $result, ToolCall $toolCall): ToolResult
            {
                throw new \RuntimeException('processor failed after cancellation');
            }
        };
        $executor = new ToolExecutor('sequential', 1, new ToolExecutionResultStore(), $container->get(ToolboxInterface::class), $contexts, $toolSets, [$processor]);
        $cancelled = $makeInvoker($executor)->invoke(new ToolInvocationRequestDTO($run, 'cancelled', 'native_probe', ['cancel' => true]));
        $this->assertTrue($handler->observedCancellation);
        $this->assertSame(RunStatus::Completed, $projection->findOperationalStatus($run)?->status);
        $this->assertTrue($cancelled->isError, 'Keep the processor failure rather than replacing it with success.');
        $this->assertStringContainsString('result could not be processed', $cancelled->text);
        $this->assertTrue($cancelled->cancelled, 'Owner continuation cannot clear the invocation cancellation latch.');
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        file_put_contents($classCwd.'/.hatfield/settings.yaml', "tools:\n    code_mode:\n        enabled: true\nextensions:\n    enabled: []\n");
    }
}
