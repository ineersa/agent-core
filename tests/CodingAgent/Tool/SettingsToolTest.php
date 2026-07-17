<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\SettingsValueResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\SettingsTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Thesis: settings tool performs singular read/set/remove with explicit
 * mutation scopes, sparse user/project writes, and clear validation errors.
 */
final class SettingsToolTest extends TestCase
{
    private string $homeDir;
    private string $projectDir;
    private string $defaultsPath;
    private SettingsTool $tool;

    protected function setUp(): void
    {
        $root = TestDirectoryIsolation::createProjectTempDir('settings-tool');
        $this->homeDir = $root.'/home';
        $this->projectDir = $root.'/project';
        mkdir($this->homeDir, 0o755, true);
        mkdir($this->projectDir, 0o755, true);

        $appRoot = \dirname(__DIR__, 3);
        $this->defaultsPath = $appRoot.'/config/hatfield.defaults.yaml';
        $pathResolver = new SettingsPathResolver($appRoot, $this->homeDir);
        $loader = new AppConfigLoader($pathResolver);
        $resources = new AppResourceLocator($appRoot);
        // Only cwd is used by SettingsTool; keep AppConfig construction minimal.
        $active = new AppConfig(
            tui: new \Ineersa\CodingAgent\Config\TuiConfig('cyberpunk'),
            logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
            cwd: $this->projectDir,
        );
        $accessor = PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor();
        $valueResolver = new SettingsValueResolver($accessor);
        $writer = new SettingsOverrideWriter($pathResolver, $accessor, new Filesystem());

        $this->tool = new SettingsTool(
            new ToolRuntime(new StackToolExecutionContextAccessor()),
            $loader,
            $resources,
            $active,
            $valueResolver,
            $writer,
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory(\dirname($this->homeDir));
    }

    public function testDefinitionIsSequentialSingularSchema(): void
    {
        $def = $this->tool->definition();
        $this->assertSame('settings', $def->name);
        $this->assertSame(ToolExecutionMode::Sequential, $def->executionMode);
        $this->assertSame(['operation', 'path'], $def->parametersJsonSchema['required']);
        $this->assertSame(['read', 'set', 'remove'], $def->parametersJsonSchema['properties']['operation']['enum']);
    }

    public function testEffectiveAndExplicitLayerReads(): void
    {
        $this->writeProject(['tui' => ['theme' => 'nord']]);

        $effective = ($this->tool)(['operation' => 'read', 'path' => 'tui.theme']);
        $this->assertTrue($effective['exists']);
        $this->assertSame('nord', $effective['value']);
        $this->assertSame('project', $effective['source']);

        $user = ($this->tool)(['operation' => 'read', 'path' => 'tui.theme', 'scope' => 'user']);
        $this->assertFalse($user['exists']);

        $project = ($this->tool)(['operation' => 'read', 'path' => 'tui.theme', 'scope' => 'project']);
        $this->assertTrue($project['exists']);
        $this->assertSame('nord', $project['value']);
        $this->assertSame('project', $project['source']);
    }

    public function testSetWritesSparseOverrideIncludingExplicitNull(): void
    {
        $set = ($this->tool)([
            'operation' => 'set',
            'path' => 'tui.theme',
            'scope' => 'project',
            'value' => 'monokai',
        ]);
        $this->assertTrue($set['changed']);
        $this->assertTrue($set['restart_required']);
        $this->assertSame('monokai', $set['value']);
        $this->assertSame('project', $set['source']);

        $nullSet = ($this->tool)([
            'operation' => 'set',
            'path' => 'logging.level',
            'scope' => 'user',
            'value' => null,
        ]);
        $this->assertTrue($nullSet['changed']);
        $this->assertNull($nullSet['value']);
        $this->assertSame('user', $nullSet['source']);
    }

    public function testRemoveResumesInheritanceAndMissingIsNoOp(): void
    {
        $this->writeProject(['tui' => ['theme' => 'nord']]);
        $removed = ($this->tool)([
            'operation' => 'remove',
            'path' => 'tui.theme',
            'scope' => 'project',
        ]);
        $this->assertTrue($removed['changed']);
        $this->assertTrue($removed['restart_required']);
        $this->assertSame('defaults', $removed['source']);

        $missing = ($this->tool)([
            'operation' => 'remove',
            'path' => 'tui.theme',
            'scope' => 'project',
        ]);
        $this->assertFalse($missing['changed']);
        $this->assertTrue($missing['restart_required']);
    }

    public function testValidationRejectsMalformedPathMissingScopeAndMissingValue(): void
    {
        try {
            ($this->tool)(['operation' => 'read', 'path' => 'tui.the]me']);
            $this->fail('Expected invalid path');
        } catch (ToolCallException $e) {
            $this->assertFalse($e->retryable());
        }

        try {
            ($this->tool)(['operation' => 'set', 'path' => 'tui.theme', 'value' => 'x']);
            $this->fail('Expected missing scope');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('scope', $e->getMessage());
        }

        try {
            ($this->tool)(['operation' => 'set', 'path' => 'tui.theme', 'scope' => 'effective', 'value' => 'x']);
            $this->fail('Expected invalid mutation scope');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('user or project', $e->getMessage());
        }

        try {
            ($this->tool)(['operation' => 'set', 'path' => 'tui.theme', 'scope' => 'project']);
            $this->fail('Expected missing value');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('value', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeProject(array $data): void
    {
        $dir = $this->projectDir.'/.hatfield';
        mkdir($dir, 0o755, true);
        file_put_contents($dir.'/settings.yaml', \Symfony\Component\Yaml\Yaml::dump($data, 4, 4));
    }
}
