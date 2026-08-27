<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser;
use Ineersa\CodingAgent\CLI\AgentsInitCommand;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Thesis: agents:init copies all bundled definitions into an isolated home,
 * fails before any writes on collision, and --force overwrites only bundled
 * filenames while preserving unrelated agents.
 */
#[CoversClass(AgentsInitCommand::class)]
final class AgentsInitCommandTest extends TestCase
{
    private string $tmpDir;
    private string $appRoot;
    private string $homeDir;
    private string $sourceDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('agents-init');
        $this->appRoot = $this->tmpDir.'/app';
        $this->homeDir = $this->tmpDir.'/home';
        $this->sourceDir = $this->appRoot.'/src/CodingAgent/Resources/agents';
        TestDirectoryIsolation::ensureDirectory($this->sourceDir);
        TestDirectoryIsolation::ensureDirectory($this->homeDir);

        $repoAgents = \dirname(__DIR__, 3).'/src/CodingAgent/Resources/agents';
        foreach (['scout.md', 'reviewer.md', 'researcher.md', 'architect.md', 'browser.md'] as $file) {
            $source = $repoAgents.'/'.$file;
            $this->assertFileExists($source, 'Bundled agent source missing: '.$source);
            (new Filesystem())->copy($source, $this->sourceDir.'/'.$file, true);
        }
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function initCopiesAllBundledDefinitionsIntoIsolatedHome(): void
    {
        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $destination = $this->homeDir.'/.hatfield/agents';
        foreach (['scout', 'reviewer', 'researcher', 'architect', 'browser'] as $name) {
            $path = $destination.'/'.$name.'.md';
            $this->assertFileExists($path);
            $this->assertFileEquals($this->sourceDir.'/'.$name.'.md', $path);
        }

        $this->assertStringContainsString('Installed 5 bundled agent definition(s)', $tester->getDisplay());
    }

    #[Test]
    public function collisionFailsBeforeAnyWritesAndForceOverwritesOnlyBundledNames(): void
    {
        $destination = $this->homeDir.'/.hatfield/agents';
        TestDirectoryIsolation::ensureDirectory($destination);
        file_put_contents($destination.'/scout.md', "---\nname: scout\ndescription: stale\n---\n\nstale scout\n");
        file_put_contents($destination.'/custom-user.md', "---\nname: custom-user\ndescription: keep me\n---\n\nuser owned\n");
        $customBefore = (string) file_get_contents($destination.'/custom-user.md');
        $staleBefore = (string) file_get_contents($destination.'/scout.md');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Refusing to overwrite existing agent definition(s)', $display);
        $this->assertStringContainsString($destination.'/scout.md', $display);
        $this->assertStringContainsString('--force', $display);
        $this->assertSame($staleBefore, (string) file_get_contents($destination.'/scout.md'));
        $this->assertFileDoesNotExist($destination.'/reviewer.md');
        $this->assertFileDoesNotExist($destination.'/researcher.md');
        $this->assertFileDoesNotExist($destination.'/architect.md');
        $this->assertFileDoesNotExist($destination.'/browser.md');
        $this->assertSame($customBefore, (string) file_get_contents($destination.'/custom-user.md'));

        $forceTester = new CommandTester($this->createCommand());
        $forceExit = $forceTester->execute(['--force' => true]);
        $this->assertSame(Command::SUCCESS, $forceExit, $forceTester->getDisplay());
        $this->assertFileEquals($this->sourceDir.'/scout.md', $destination.'/scout.md');
        $this->assertFileEquals($this->sourceDir.'/reviewer.md', $destination.'/reviewer.md');
        $this->assertFileEquals($this->sourceDir.'/researcher.md', $destination.'/researcher.md');
        $this->assertFileEquals($this->sourceDir.'/architect.md', $destination.'/architect.md');
        $this->assertFileEquals($this->sourceDir.'/browser.md', $destination.'/browser.md');
        $this->assertSame($customBefore, (string) file_get_contents($destination.'/custom-user.md'));
    }

    #[Test]
    public function bundledDefinitionsAreParserValid(): void
    {
        $parser = $this->createParser();
        $expectedParallelAllowed = [
            'scout' => true,
            'reviewer' => true,
            'researcher' => false,
            'architect' => true,
            'browser' => false,
        ];
        foreach ($expectedParallelAllowed as $name => $parallelAllowed) {
            $path = $this->sourceDir.'/'.$name.'.md';
            $dto = $parser->parseFile($path);
            $this->assertSame($name, $dto->name);
            $this->assertNotSame('', $dto->description);
            $this->assertNotSame('', trim($dto->instructions));
            $this->assertSame(
                $parallelAllowed,
                $dto->parallelAllowed,
                \sprintf('bundled agent "%s" parallelAllowed mismatch', $name),
            );
            if ('reviewer' === $name) {
                $this->assertSame('zai/glm-5.3', $dto->model);
                $this->assertSame('medium', $dto->thinking);
            }
        }
    }

    private function createCommand(): AgentsInitCommand
    {
        return new AgentsInitCommand(
            resources: new AppResourceLocator($this->appRoot),
            pathResolver: new SettingsPathResolver($this->appRoot, $this->homeDir),
            filesystem: new Filesystem(),
        );
    }

    private function createParser(): AgentDefinitionParser
    {
        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create();

        return new AgentDefinitionParser(
            frontmatterParser: new AgentFrontmatterParser(new MarkdownFrontmatterExtractor()),
            denormalizer: $serializer,
            validator: $validator,
        );
    }
}
