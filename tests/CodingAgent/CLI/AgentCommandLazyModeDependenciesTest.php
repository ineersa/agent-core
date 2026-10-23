<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\CLI\AgentCommand;
use Ineersa\CodingAgent\Runtime\Controller\HeadlessController;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Application\InteractiveMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\InvokableCommand;

/**
 * Proves mode-specific AgentCommand dependencies stay uninitialized until used.
 *
 * @covers \Ineersa\CodingAgent\CLI\AgentCommand
 */
#[CoversClass(AgentCommand::class)]
final class AgentCommandLazyModeDependenciesTest extends IsolatedKernelTestCase
{
    #[Test]
    public function resolvingAgentCommandDoesNotInitializeModeSpecificDependencies(): void
    {
        $agentCommand = $this->resolveContainerAgentCommand();

        $this->assertLazyUninitialized($agentCommand, 'inProcessClient', InProcessAgentSessionClient::class);
        $this->assertLazyUninitialized($agentCommand, 'processClient', JsonlProcessAgentSessionClient::class);
        $this->assertLazyUninitialized($agentCommand, 'interactiveMode', InteractiveMode::class);
        $this->assertLazyUninitialized($agentCommand, 'controller', HeadlessController::class);
    }

    private function resolveContainerAgentCommand(): AgentCommand
    {
        $application = new Application(self::$kernel);
        $command = $application->find('agent')->getCommand();

        $codeProperty = new \ReflectionProperty(Command::class, 'code');
        /** @var InvokableCommand $invokable */
        $invokable = $codeProperty->getValue($command);

        $invokableCodeProperty = new \ReflectionProperty(InvokableCommand::class, 'code');
        /** @var \Closure $closure */
        $closure = $invokableCodeProperty->getValue($invokable);
        $agentCommand = (new \ReflectionFunction($closure))->getClosureThis();
        $this->assertInstanceOf(AgentCommand::class, $agentCommand);

        return $agentCommand;
    }

    /**
     * @param class-string $expectedType
     */
    private function assertLazyUninitialized(AgentCommand $agentCommand, string $property, string $expectedType): void
    {
        $value = (new \ReflectionProperty(AgentCommand::class, $property))->getValue($agentCommand);
        $this->assertInstanceOf($expectedType, $value);
        $this->assertTrue(
            (new \ReflectionClass($value))->isUninitializedLazyObject($value),
            \sprintf('Expected %s to remain uninitialized after AgentCommand resolution.', $property),
        );
    }
}
