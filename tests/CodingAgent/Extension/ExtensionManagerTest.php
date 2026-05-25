<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionApiBridge;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\ToolRegistrationDTO;
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

    // ── Tests: ExtensionApiBridge ──

    public function testBridgeCollectsRegistrations(): void
    {
        $bridge = new ExtensionApiBridge();

        $dto1 = new ToolRegistrationDTO(name: 'tool_a', description: 'A', parametersJsonSchema: [], handler: null);
        $dto2 = new ToolRegistrationDTO(name: 'tool_b', description: 'B', parametersJsonSchema: [], handler: null);

        $bridge->registerTool($dto1);
        $bridge->registerTool($dto2);

        $this->assertCount(2, $bridge->getRegistrations());
        $this->assertSame('tool_a', $bridge->getRegistrations()[0]->name);
        $this->assertSame('tool_b', $bridge->getRegistrations()[1]->name);
    }

    public function testBridgeDrainClearsRegistrations(): void
    {
        $bridge = new ExtensionApiBridge();
        $bridge->registerTool(
            new ToolRegistrationDTO(name: 'tool_x', description: 'X', parametersJsonSchema: [], handler: null)
        );

        $drained = $bridge->drainRegistrations();
        $this->assertCount(1, $drained);
        $this->assertSame('tool_x', $drained[0]->name);
        $this->assertCount(0, $bridge->getRegistrations());
    }

    public function testBridgeEmptyDrainReturnsEmptyArray(): void
    {
        $bridge = new ExtensionApiBridge();
        $this->assertSame([], $bridge->drainRegistrations());
        $this->assertSame([], $bridge->getRegistrations());
    }

    // ── Tests: ExtensionManager ──

    public function testLoadExtensionsWithEmptyListDoesNothing(): void
    {
        $config = $this->createAppConfig(cwd: $this->extensionsDir, extensions: []);
        $bridge = new ExtensionApiBridge();
        $logger = new NullLogger();

        $manager = new ExtensionManager($config, $bridge, $logger);
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
use Ineersa\Hatfield\ExtensionApi\ToolRegistrationDTO;

class SampleExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $api->registerTool(new ToolRegistrationDTO(
            name: 'sample_tool',
            description: 'A sample extension tool',
            parametersJsonSchema: [],
            handler: null,
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
        $bridge = new ExtensionApiBridge();
        $logger = new NullLogger();

        $manager = new ExtensionManager($config, $bridge, $logger);
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
        $bridge = new ExtensionApiBridge();
        $logger = new LoggerSpy();

        $manager = new ExtensionManager($config, $bridge, $logger);
        $manager->loadExtensions();

        $this->assertCount(0, $bridge->getRegistrations());
        $this->assertCount(1, $logger->warnings);
        $this->assertStringContainsString('Extension class not found', $logger->warnings[0]);
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
        $bridge = new ExtensionApiBridge();
        $logger = new LoggerSpy();

        $manager = new ExtensionManager($config, $bridge, $logger);
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
use Ineersa\Hatfield\ExtensionApi\ToolRegistrationDTO;

class GoodExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $api->registerTool(new ToolRegistrationDTO(name: 'good_tool', description: 'Good', parametersJsonSchema: [], handler: null));
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
use Ineersa\Hatfield\ExtensionApi\ToolRegistrationDTO;

class AnotherGoodExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $api->registerTool(new ToolRegistrationDTO(name: 'another_tool', description: 'Another', parametersJsonSchema: [], handler: null));
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
        $bridge = new ExtensionApiBridge();
        $logger = new LoggerSpy();

        $manager = new ExtensionManager($config, $bridge, $logger);
        $manager->loadExtensions();

        // Both good extensions should have registered their tools
        // despite the middleware failure
        $names = array_map(static fn (ToolRegistrationDTO $dto): string => $dto->name, $bridge->getRegistrations());
        $this->assertContains('good_tool', $names);
        $this->assertContains('another_tool', $names);
        $this->assertCount(2, $bridge->getRegistrations());
        $this->assertGreaterThanOrEqual(1, $logger->errors);
    }

    public function testLoadExtensionsHandlesNonArrayEnabledGracefully(): void
    {
        $tui = new TuiConfig(theme: 'cyberpunk', themePaths: []);
        $logging = new LoggingConfig(logDir: $this->extensionsDir.'/var/tmp', level: Level::Info, maxFiles: 7);
        $config = new AppConfig(
            tui: $tui,
            logging: $logging,
            raw: ['extensions' => ['enabled' => 'not_an_array']],
            cwd: $this->extensionsDir,
        );
        $bridge = new ExtensionApiBridge();
        $logger = new LoggerSpy();

        $manager = new ExtensionManager($config, $bridge, $logger);
        $manager->loadExtensions();

        $this->assertCount(0, $bridge->getRegistrations());
        $this->assertCount(1, $logger->warnings);
        $this->assertStringContainsString('extensions.enabled is not a list', $logger->warnings[0]);
    }

    public function testLoadExtensionsWithoutAutoloadStillLoadsKnownClasses(): void
    {
        // Don't create the autoload file; load a class that PHP already knows
        $bridge = new ExtensionApiBridge();
        $logger = new NullLogger();

        // HatfieldExtensionInterface does NOT implement HatfieldExtensionInterface,
        // so this will be skipped. We're just verifying no crash when autoload is absent.
        $config = $this->createAppConfig(
            cwd: $this->extensionsDir,
            extensions: ['Ineersa\\Hatfield\\ExtensionApi\\ExtensionApiInterface']
        );

        $manager = new ExtensionManager($config, $bridge, $logger);
        $manager->loadExtensions();

        $this->assertCount(0, $bridge->getRegistrations());
    }

    // ── Helpers ──

    /**
     * @param list<class-string>   $extensions
     * @param array<string, mixed> $rawOverrides
     */
    private function createAppConfig(
        string $cwd,
        array $extensions = [],
        array $rawOverrides = [],
    ): AppConfig {
        $tui = new TuiConfig(theme: 'cyberpunk', themePaths: []);
        $logging = new LoggingConfig(logDir: $cwd.'/var/tmp', level: Level::Info, maxFiles: 7);

        $raw = array_merge([
            'extensions' => ['enabled' => $extensions],
        ], $rawOverrides);

        return new AppConfig(
            tui: $tui,
            logging: $logging,
            raw: $raw,
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
