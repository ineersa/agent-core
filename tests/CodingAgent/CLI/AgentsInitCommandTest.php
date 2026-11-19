<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser;
use Ineersa\CodingAgent\Agent\Definition\SystemPromptModeEnum;
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
        foreach (['scout.md', 'reviewer.md', 'verifier.md', 'researcher.md', 'general-purpose.md', 'browser.md'] as $file) {
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
        foreach (['scout', 'reviewer', 'verifier', 'researcher', 'general-purpose', 'browser'] as $name) {
            $path = $destination.'/'.$name.'.md';
            $this->assertFileExists($path);
            $this->assertFileEquals($this->sourceDir.'/'.$name.'.md', $path);
        }

        $this->assertStringContainsString('Installed 6 bundled agent definition(s)', $tester->getDisplay());
    }

    #[Test]
    public function collisionFailsBeforeAnyWritesAndForceOverwritesOnlyBundledNames(): void
    {
        $destination = $this->homeDir.'/.hatfield/agents';
        TestDirectoryIsolation::ensureDirectory($destination);
        file_put_contents($destination.'/scout.md', "---\nname: scout\ndescription: stale\n---\n\nstale scout\n");
        file_put_contents($destination.'/architect.md', "---\nname: architect\ndescription: keep me\n---\n\nuser owned\n");
        $customBefore = (string) file_get_contents($destination.'/architect.md');
        $staleBefore = (string) file_get_contents($destination.'/scout.md');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Refusing to overwrite existing agent definition(s)', $display);
        $withoutConsoleWhitespace = static fn (string $text): string => str_replace(["\r", "\n", ' '], '', $text);
        $this->assertStringContainsString($withoutConsoleWhitespace($destination.'/scout.md'), $withoutConsoleWhitespace($display));
        $this->assertStringContainsString('--force', $display);
        $this->assertSame($staleBefore, (string) file_get_contents($destination.'/scout.md'));
        $this->assertFileDoesNotExist($destination.'/reviewer.md');
        $this->assertFileDoesNotExist($destination.'/verifier.md');
        $this->assertFileDoesNotExist($destination.'/researcher.md');
        $this->assertFileDoesNotExist($destination.'/general-purpose.md');
        $this->assertFileDoesNotExist($destination.'/browser.md');
        $this->assertSame($customBefore, (string) file_get_contents($destination.'/architect.md'));

        $forceTester = new CommandTester($this->createCommand());
        $forceExit = $forceTester->execute(['--force' => true]);
        $this->assertSame(Command::SUCCESS, $forceExit, $forceTester->getDisplay());
        $this->assertFileEquals($this->sourceDir.'/scout.md', $destination.'/scout.md');
        $this->assertFileEquals($this->sourceDir.'/reviewer.md', $destination.'/reviewer.md');
        $this->assertFileEquals($this->sourceDir.'/verifier.md', $destination.'/verifier.md');
        $this->assertFileEquals($this->sourceDir.'/researcher.md', $destination.'/researcher.md');
        $this->assertFileEquals($this->sourceDir.'/general-purpose.md', $destination.'/general-purpose.md');
        $this->assertFileEquals($this->sourceDir.'/browser.md', $destination.'/browser.md');
        $this->assertSame($customBefore, (string) file_get_contents($destination.'/architect.md'));
    }

    #[Test]
    public function bundledDefinitionsAreParserValid(): void
    {
        $parser = $this->createParser();
        foreach (['scout', 'reviewer', 'verifier', 'researcher', 'general-purpose', 'browser'] as $name) {
            $path = $this->sourceDir.'/'.$name.'.md';
            $dto = $parser->parseFile($path);
            $this->assertSame($name, $dto->name);
            $this->assertNotSame('', $dto->description);
            $this->assertNotSame('', trim($dto->instructions));
        }
    }

    #[Test]
    public function reviewAgentsUsePinnedModelAndThinkingWithRestrictedInspectionTools(): void
    {
        foreach (['reviewer', 'verifier'] as $name) {
            $agent = $this->createParser()->parseFile($this->sourceDir.'/'.$name.'.md');

            $this->assertSame('mistral/zai-glm-5-3-flash', $agent->model);
            $this->assertSame('high', $agent->thinking);
            $this->assertSame(['read', 'bash', 'mcp:-'], $agent->tools);
            $this->assertTrue($agent->inheritProjectContext);
        }
    }

    #[Test]
    public function generalPurposeInheritsExecutionDefaultsButUsesItsOwnPrompt(): void
    {
        $agent = $this->createParser()->parseFile($this->sourceDir.'/general-purpose.md');

        $this->assertNull($agent->model);
        $this->assertNull($agent->thinking);
        $this->assertNull($agent->tools);
        $this->assertTrue($agent->inheritProjectContext);
        $this->assertSame(SystemPromptModeEnum::Replace, $agent->systemPromptMode);
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
