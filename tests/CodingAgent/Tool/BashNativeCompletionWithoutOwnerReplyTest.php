<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessFinishedDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessStartedDTO;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BashTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Foreground Bash returns native completion without awaiting owner metadata replies.
 *
 * Lost coverage relative to reply-RPC proofs: this does not exercise
 * BashRegistrationClient register/observe awaits. Those waits are the defect
 * under removal for the Bash tool path.
 */
final class BashNativeCompletionWithoutOwnerReplyTest extends IsolatedKernelTestCase
{
    public function testForegroundBashCompletesFromNativeStatusWithoutOwnerReply(): void
    {
        $temporary = TestDirectoryIsolation::createProjectTempDir('bash-native-completion');
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                // Publish only. Never complete a correlated reply Future.
                return new Envelope($message, [new HandledStamp(null, 'test')]);
            }
        };
        $store = self::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle(
            new BackgroundProcessConfig(storageDir: $temporary.'/bg'),
            new NullLogger(),
            new LockFactory(new FlockStore($temporary.'/locks')),
        );
        $manager = new BackgroundProcessManager(
            $store,
            $lifecycle,
            new BackgroundProcessConfig(storageDir: $temporary.'/bg'),
            new NullLogger(),
            $bus,
        );
        // Intentionally leave processOwnerClient unset: no decide()/register() RPC.
        $accessor = new StackToolExecutionContextAccessor();
        $tool = new BashTool(
            manager: $manager,
            contextAccessor: $accessor,
            toolRuntime: new ToolRuntime($accessor),
            logger: new TestLogger(),
            config: new BashToolConfig(pollIntervalMicros: 1_000),
        );

        $output = $accessor->with(
            new ToolContext('97', 1, 'call_native', 'bash', new NullCancellationToken(), 1, stepId: 'step-native'),
            static fn (): string => $tool(new BashArgumentsDTO(command: "printf 'native-ok\\n'")),
        );

        $this->assertSame("native-ok\n", $output);
        $started = array_values(array_filter($bus->messages, static fn (object $message): bool => $message instanceof BashProcessStartedDTO));
        $finished = array_values(array_filter($bus->messages, static fn (object $message): bool => $message instanceof BashProcessFinishedDTO));
        $this->assertCount(1, $started);
        $this->assertSame('97', $started[0]->runId);
        $this->assertSame('call_native', $started[0]->toolCallId);
        $this->assertNotSame('', $started[0]->statusPath);
        $this->assertGreaterThanOrEqual(1, \count($finished));
        $this->assertSame($started[0]->statusPath, $finished[0]->statusPath);
        $this->assertSame(0, $finished[0]->exitCode);
        foreach ($bus->messages as $message) {
            $this->assertNotInstanceOf(\Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessCommandDTO::class, $message);
            $this->assertNotInstanceOf(\Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessCommandDTO::class, $message);
        }
    }
}
