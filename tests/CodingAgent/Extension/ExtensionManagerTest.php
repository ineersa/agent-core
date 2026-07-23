<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Tests\Extension\Support\NoOpExtensionToolHandler;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ExtensionManagerTest extends TestCase
{
    private string $extensionsDir;
    private string $autoloadPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extensionsDir = sys_get_temp_dir().'/hatfield-ext-test-'.bin2hex(random_bytes(8));
        mkdir($this->extensionsDir.'/.hatfield/extensions/vendor', 0755, true);
        $this->autoloadPath = $this->extensionsDir.'/.hatfield/extensions/vendor/autoload.php';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmdirRecursive($this->extensionsDir);
    }

    // ── Tests: InMemoryExtensionApiBridge ──

    public function testBridgeCollectsRegistrations(): void
    {
        $bridge = new InMemoryExtensionApiBridge();

        $dto1 = new ToolRegistrationDTO(name: 'tool_a', description: 'A', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler());
        $dto2 = new ToolRegistrationDTO(name: 'tool_b', description: 'B', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler());

        $bridge->registerTool($dto1);
        $bridge->registerTool($dto2);

        $this->assertCount(2, $bridge->getRegistrations());
        $this->assertSame('tool_a', $bridge->getRegistrations()[0]->name);
        $this->assertSame('tool_b', $bridge->getRegistrations()[1]->name);
    }

    public function testBridgeDrainClearsRegistrations(): void
    {
        $bridge = new InMemoryExtensionApiBridge();
        $bridge->registerTool(
            new ToolRegistrationDTO(name: 'tool_x', description: 'X', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler())
        );

        $drained = $bridge->drainRegistrations();
        $this->assertCount(1, $drained);
        $this->assertSame('tool_x', $drained[0]->name);
        $this->assertCount(0, $bridge->getRegistrations());
    }

    public function testBridgeEmptyDrainReturnsEmptyArray(): void
    {
        $bridge = new InMemoryExtensionApiBridge();
        $this->assertSame([], $bridge->drainRegistrations());
        $this->assertSame([], $bridge->getRegistrations());
    }

    // ── InMemoryExtensionApiBridge hook methods ──

    public function testBridgeCollectsToolCallHooks(): void
    {
        $bridge = new InMemoryExtensionApiBridge();
        $hookA = $this->dummyToolCallHook('hook_a');
        $hookB = $this->dummyToolCallHook('hook_b');

        $bridge->registerToolCallHook($hookA);
        $bridge->registerToolCallHook($hookB);

        $this->assertCount(2, $bridge->getToolCallHooks());
        $this->assertSame($hookA, $bridge->getToolCallHooks()[0]);
        $this->assertSame($hookB, $bridge->getToolCallHooks()[1]);
    }

    public function testBridgeCollectsToolResultHooks(): void
    {
        $bridge = new InMemoryExtensionApiBridge();
        $hookA = $this->dummyToolResultHook('result_a');
        $hookB = $this->dummyToolResultHook('result_b');

        $bridge->registerToolResultHook($hookA);
        $bridge->registerToolResultHook($hookB);

        $this->assertCount(2, $bridge->getToolResultHooks());
        $this->assertSame($hookA, $bridge->getToolResultHooks()[0]);
        $this->assertSame($hookB, $bridge->getToolResultHooks()[1]);
    }

    public function testBridgeHooksCoexistWithTools(): void
    {
        $bridge = new InMemoryExtensionApiBridge();

        $bridge->registerTool(new ToolRegistrationDTO(name: 'tool_x', description: 'X', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler()));
        $bridge->registerToolCallHook($this->dummyToolCallHook('hook_x'));
        $bridge->registerToolResultHook($this->dummyToolResultHook('result_x'));

        $this->assertCount(1, $bridge->getRegistrations());
        $this->assertCount(1, $bridge->getToolCallHooks());
        $this->assertCount(1, $bridge->getToolResultHooks());
    }

    // ── Tests: ExtensionManager ──

    public function testLoadExtensionsWithEmptyListDoesNothing(): void
    {
        $config = $this->createAppConfig(cwd: $this->extensionsDir, extensions: []);
        $bridge = new InMemoryExtensionApiBridge();
        $logger = new NullLogger();

        $manager = new ExtensionManager($config, $bridge, $logger, new \Symfony\Component\EventDispatcher\EventDispatcher());
        $manager->loadExtensions();

        $this->assertCount(0, $bridge->getRegistrations());
    }

    public function testLoadExtensionsRequiresAutoloadWhenPresent(): void
    {
        // Create an autoloader that registers a test class
        $autoloadCode = <<<'PHP'
<?php
// Autoloader that maps the test extension namespace
spl_autoload_register(function (string $class): void {
    if ('HatfieldExtTest\\SampleExtension' === $class) {
        require_once __DIR__ . '/SampleExtension.php';
    }
});
PHP;
        file_put_contents($this->autoloadPath, $autoloadCode);

        // Create the sample extension class file next to autoload.php
        $extensionCode = <<<'PHP'
<?php
namespace HatfieldExtTest;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\CodingAgent\Tests\Extension\Support\NoOpExtensionToolHandler;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;

class SampleExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $api->registerTool(new ToolRegistrationDTO(
            name: 'sample_tool',
            description: 'A sample extension tool',
            parametersJsonSchema: [],
            handler: new NoOpExtensionToolHandler(),
        ));
    }
}
PHP;
        file_put_contents(
            \dirname($this->autoloadPath).'/SampleExtension.php',
            $extensionCode
        );

        $config = $this->createAppConfig(
            cwd: $this->extensionsDir,
            extensions: ['HatfieldExtTest\\SampleExtension']
        );
        $bridge = new InMemoryExtensionApiBridge();
        $logger = new NullLogger();

        $manager = new ExtensionManager($config, $bridge, $logger, new \Symfony\Component\EventDispatcher\EventDispatcher());
        $manager->loadExtensions();

        $this->assertCount(1, $bridge->getRegistrations());
        $this->assertSame('sample_tool', $bridge->getRegistrations()[0]->name);
    }

    public function testLoadExtensionsSkipsMissingClass(): void
    {
        $config = $this->createAppConfig(
            cwd: $this->extensionsDir,
            extensions: ['NoSuch\\NonExistentExtension']
        );
        $bridge = new InMemoryExtensionApiBridge();
        $logger = new LoggerSpy();

        $manager = new ExtensionManager($config, $bridge, $logger, new \Symfony\Component\EventDispatcher\EventDispatcher());
        $manager->loadExtensions();

        $this->assertCount(0, $bridge->getRegistrations());
        $this->assertCount(1, $logger->warnings);
        $this->assertStringContainsString('not found', $logger->warnings[0]);
    }

    public function testLoadExtensionsSkipsNonHatfieldExtension(): void
    {
        $autoloadCode = <<<'PHP'
<?php
spl_autoload_register(function (string $class): void {
    if ('HatfieldExtTest\\PlainClass' === $class) {
        require_once __DIR__ . '/PlainClass.php';
    }
});
PHP;
        file_put_contents($this->autoloadPath, $autoloadCode);

        file_put_contents(
            \dirname($this->autoloadPath).'/PlainClass.php',
            <<<'PHP'
<?php
namespace HatfieldExtTest;
class PlainClass {}
PHP
        );

        $config = $this->createAppConfig(
            cwd: $this->extensionsDir,
            extensions: ['HatfieldExtTest\\PlainClass']
        );
        $bridge = new InMemoryExtensionApiBridge();
        $logger = new LoggerSpy();

        $manager = new ExtensionManager($config, $bridge, $logger, new \Symfony\Component\EventDispatcher\EventDispatcher());
        $manager->loadExtensions();

        $this->assertCount(0, $bridge->getRegistrations());
        $this->assertCount(1, $logger->warnings);
        $this->assertStringContainsString('does not implement HatfieldExtensionInterface', $logger->warnings[0]);
    }

    public function testLoadExtensionsContinuesAfterSingleFailure(): void
    {
        $autoloadCode = <<<'PHP'
<?php
spl_autoload_register(function (string $class): void {
    $map = [
        'HatfieldExtTest\\GoodExtension',
        'HatfieldExtTest\\FailingExtension',
        'HatfieldExtTest\\AnotherGoodExtension',
    ];
    if (in_array($class, $map, true)) {
        require_once __DIR__ . '/' . substr(strrchr($class, '\\') ?: $class, 1) . '.php';
    }
});
PHP;
        file_put_contents($this->autoloadPath, $autoloadCode);

        // Good extension
        file_put_contents(
            \dirname($this->autoloadPath).'/GoodExtension.php',
            <<<'PHP'
<?php
namespace HatfieldExtTest;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\CodingAgent\Tests\Extension\Support\NoOpExtensionToolHandler;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;

class GoodExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $api->registerTool(new ToolRegistrationDTO(name: 'good_tool', description: 'Good', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler()));
    }
}
PHP
        );

        // Failing extension (throws on register)
        file_put_contents(
            \dirname($this->autoloadPath).'/FailingExtension.php',
            <<<'PHP'
<?php
namespace HatfieldExtTest;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;

class FailingExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        throw new \RuntimeException('Something went wrong');
    }
}
PHP
        );

        // Another good extension
        file_put_contents(
            \dirname($this->autoloadPath).'/AnotherGoodExtension.php',
            <<<'PHP'
<?php
namespace HatfieldExtTest;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\CodingAgent\Tests\Extension\Support\NoOpExtensionToolHandler;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;

class AnotherGoodExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $api->registerTool(new ToolRegistrationDTO(name: 'another_tool', description: 'Another', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler()));
    }
}
PHP
        );

        $config = $this->createAppConfig(
            cwd: $this->extensionsDir,
            extensions: [
                'HatfieldExtTest\\GoodExtension',
                'HatfieldExtTest\\FailingExtension',
                'HatfieldExtTest\\AnotherGoodExtension',
            ]
        );
        $bridge = new InMemoryExtensionApiBridge();
        $logger = new LoggerSpy();

        $manager = new ExtensionManager($config, $bridge, $logger, new \Symfony\Component\EventDispatcher\EventDispatcher());
        $manager->loadExtensions();

        // Both good extensions should have registered their tools
        // despite the middleware failure
        $names = array_map(static fn (ToolRegistrationDTO $dto): string => $dto->name, $bridge->getRegistrations());
        $this->assertContains('good_tool', $names);
        $this->assertContains('another_tool', $names);
        $this->assertCount(2, $bridge->getRegistrations());
        $this->assertGreaterThanOrEqual(1, $logger->errors);
    }

    public function testLoadExtensionsEmptyListLoadsNothing(): void
    {
        $config = $this->createAppConfig(
            cwd: $this->extensionsDir,
            extensions: [],
        );
        $bridge = new InMemoryExtensionApiBridge();
        $logger = new NullLogger();

        $manager = new ExtensionManager($config, $bridge, $logger, new \Symfony\Component\EventDispatcher\EventDispatcher());
        $manager->loadExtensions();

        $this->assertCount(0, $bridge->getRegistrations());
    }

    public function testLoadExtensionsWithoutAutoloadStillLoadsKnownClasses(): void
    {
        // Don't create the autoload file; load a class that PHP already knows
        $bridge = new InMemoryExtensionApiBridge();
        $logger = new NullLogger();

        // HatfieldExtensionInterface does NOT implement HatfieldExtensionInterface,
        // so this will be skipped. We're just verifying no crash when autoload is absent.
        $config = $this->createAppConfig(
            cwd: $this->extensionsDir,
            extensions: ['Ineersa\\Hatfield\\ExtensionApi\\ExtensionApiInterface']
        );

        $manager = new ExtensionManager($config, $bridge, $logger, new \Symfony\Component\EventDispatcher\EventDispatcher());
        $manager->loadExtensions();

        $this->assertCount(0, $bridge->getRegistrations());
    }

    public function testLoadExtensionsWithHookRegistration(): void
    {
        $autoloadCode = <<<'PHP'
<?php
spl_autoload_register(function (string $class): void {
    if ('HatfieldExtTest\\HookRegistrationExtension' === $class) {
        require_once __DIR__ . '/HookRegistrationExtension.php';
    }
});
PHP;
        file_put_contents($this->autoloadPath, $autoloadCode);

        file_put_contents(
            \dirname($this->autoloadPath).'/HookRegistrationExtension.php',
            <<<'PHP'
<?php
namespace HatfieldExtTest;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\CodingAgent\Tests\Extension\Support\NoOpExtensionToolHandler;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;

class HookRegistrationExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $api->registerTool(new ToolRegistrationDTO(name: 'hook_ext_tool', description: 'Tool from hook extension', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler()));
        $api->registerToolCallHook(new class implements ToolCallHookInterface {
            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                return ToolCallDecisionDTO::allow();
            }
        });
        $api->registerToolResultHook(new class implements ToolResultHookInterface {
            public function onToolResult(ToolResultContextDTO $context): ToolResultDecisionDTO
            {
                return ToolResultDecisionDTO::keep();
            }
        });
    }
}
PHP
        );

        $config = $this->createAppConfig(
            cwd: $this->extensionsDir,
            extensions: ['HatfieldExtTest\\HookRegistrationExtension']
        );
        $bridge = new InMemoryExtensionApiBridge();
        $logger = new NullLogger();

        $manager = new ExtensionManager($config, $bridge, $logger, new \Symfony\Component\EventDispatcher\EventDispatcher());
        $manager->loadExtensions();

        // Verify tool registration
        $this->assertCount(1, $bridge->getRegistrations());
        $this->assertSame('hook_ext_tool', $bridge->getRegistrations()[0]->name);

        // Verify hook registrations
        $this->assertCount(1, $bridge->getToolCallHooks());
        $this->assertCount(1, $bridge->getToolResultHooks());
    }

    public function testLoadExtensionsInjectsLoggerAndRegistersEventSubscribers(): void
    {
        $autoloadCode = <<<'PHP'
<?php
spl_autoload_register(function (string $class): void {
    if ('HatfieldExtTest\\SubscriberAwareExtension' === $class) {
        require_once __DIR__ . '/SubscriberAwareExtension.php';
    }
});
PHP;
        file_put_contents($this->autoloadPath, $autoloadCode);

        file_put_contents(
            \dirname($this->autoloadPath).'/SubscriberAwareExtension.php',
            <<<'PHP'
<?php
namespace HatfieldExtTest;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SubscriberAwareExtension implements HatfieldExtensionInterface, LoggerAwareInterface, EventSubscriberInterface
{
    public static ?LoggerInterface $injectedLogger = null;
    public static int $subscriberCalls = 0;

    public function setLogger(LoggerInterface $logger): void
    {
        self::$injectedLogger = $logger;
    }

    public function register(ExtensionApiInterface $api): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['om.test.event' => 'onTestEvent'];
    }

    public function onTestEvent(): void
    {
        ++self::$subscriberCalls;
    }
}
PHP
        );

        $config = $this->createAppConfig(
            cwd: $this->extensionsDir,
            extensions: ['HatfieldExtTest\\SubscriberAwareExtension']
        );
        $bridge = new InMemoryExtensionApiBridge();
        $logger = new NullLogger();
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();

        $manager = new ExtensionManager($config, $bridge, $logger, $dispatcher);
        $manager->loadExtensions();

        $this->assertSame($logger, \HatfieldExtTest\SubscriberAwareExtension::$injectedLogger);
        $dispatcher->dispatch(new \stdClass(), 'om.test.event');
        $this->assertSame(1, \HatfieldExtTest\SubscriberAwareExtension::$subscriberCalls);
    }

    // ── Helpers ──

    private function dummyToolCallHook(string $label = 'test'): ToolCallHookInterface
    {
        return new class($label) implements ToolCallHookInterface {
            public function __construct(private readonly string $label)
            {
            }

            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                return ToolCallDecisionDTO::allow();
            }

            public function label(): string
            {
                return $this->label;
            }
        };
    }

    private function dummyToolResultHook(string $label = 'test'): ToolResultHookInterface
    {
        return new class($label) implements ToolResultHookInterface {
            public function __construct(private readonly string $label)
            {
            }

            public function onToolResult(ToolResultContextDTO $context): ToolResultDecisionDTO
            {
                return ToolResultDecisionDTO::keep();
            }

            public function label(): string
            {
                return $this->label;
            }
        };
    }

    /**
     * @param list<class-string> $extensions
     */
    private function createAppConfig(
        string $cwd,
        array $extensions = [],
    ): AppConfig {
        $tui = new TuiConfig(theme: 'cyberpunk', themePaths: []);
        $logging = new LoggingConfig(logDir: $cwd.'/var/tmp', level: Level::Info, maxFiles: 7);

        return new AppConfig(
            tui: $tui,
            logging: $logging,
            extensions: new ExtensionsConfig(enabled: $extensions),
            cwd: $cwd,
        );
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir((string) $item);
            } else {
                unlink((string) $item);
            }
        }

        rmdir($dir);
    }
}

/**
 * Simple logger spy for testing — avoids PHPUnit mock compatibility
 * issues with PHP 8.5.
 *
 * @internal
 */
final class LoggerSpy implements LoggerInterface
{
    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $logs = [];

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
        $this->logs[] = (string) $message;
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->errors[] = (string) $message;
        $this->logs[] = (string) $message;
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = (string) $message;
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = (string) $message;
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = (string) $message;
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = (string) $message;
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = (string) $message;
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = (string) $message;
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = (string) $message;
    }
}
