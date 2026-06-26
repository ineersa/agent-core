<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient
 *
 * Tests that PromptTemplatesRuntimeConfig.controllerArgs() output
 * is forwarded to the spawned controller process argv.
 */
final class JsonlProcessPromptTemplateOptionsTest extends TestCase
{
    private string $tmpDir;
    private string $fakeScript;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('pt-jsonl');
        $this->fakeScript = $this->tmpDir.'/controller.php';

        // Fake controller: argv[2] is --argv-dump=<path> (injected by our locator).
        // Writes the agent args (everything after the fake script) to dump file,
        // emits runtime.ready, reads one line, emits run.started, exits.
        file_put_contents($this->fakeScript, <<<'PHP'
<?php
// Find --argv-dump=<path> in our own argv and collect the "real" agent args.
// With proc_open array mode, PHP_BINARY is NOT in argv[0]; the script gets
// argv[0]=script_path, argv[1]=--argv-dump=..., argv[2]='agent', ...
$dumpFile = null;
$realArgs = [];
foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue; // Skip script path
    }
    if (str_starts_with($arg, '--argv-dump=')) {
        $dumpFile = substr($arg, strlen('--argv-dump='));
        continue;
    }
    $realArgs[] = $arg;
}
if (null === $dumpFile) {
    fwrite(STDERR, "Fake controller: --argv-dump= not found in argv\n");
    exit(1);
}
// Dump only the "real" args (everything after the script path except --argv-dump=).
file_put_contents($dumpFile, json_encode($realArgs));

// Emit runtime.ready so waitForRuntimeReady() completes.
fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => ['version' => '1.0']]) . "\n");
fflush(STDOUT);

// Read one JSONL line (the start_run command the parent writes after runtime.ready).
$line = fgets(STDIN);
if (false === $line) { exit(0); }
$cmd = json_decode(trim($line), true);
if (null === $cmd || !isset($cmd['type']) || 'start_run' !== $cmd['type']) {
    fwrite(STDERR, "Fake controller: unexpected input\n");
    exit(1);
}

// Emit run.started and exit cleanly.
fwrite(STDOUT, json_encode(['type' => 'run.started', 'runId' => 'test-run', 'seq' => 1, 'payload' => ['status' => 'running']]) . "\n");
fflush(STDOUT);
exit(0);
PHP);

        chmod($this->fakeScript, 0o755);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    // ── Tests ──────────────────────────────────────────────────────

    public function testNoPromptTemplateConfigProducesNoExtraArgs(): void
    {
        $argv = $this->startAndCaptureArgv(promptTemplatePaths: [], noPromptTemplates: false);

        self::assertContains('agent', $argv);
        self::assertContains('--controller', $argv);
        self::assertNotContains('--no-prompt-templates', $argv);
        foreach ($argv as $arg) {
            self::assertStringStartsNotWith('--prompt-template=', $arg);
        }
    }

    public function testRepeatedPromptTemplatePathsAppearInChildArgv(): void
    {
        $argv = $this->startAndCaptureArgv(promptTemplatePaths: ['/path1.md', '/path2.md'], noPromptTemplates: false);

        self::assertContains('--prompt-template=/path1.md', $argv);
        self::assertContains('--prompt-template=/path2.md', $argv);
    }

    public function testNoPromptTemplatesFlagInChildArgv(): void
    {
        $argv = $this->startAndCaptureArgv(promptTemplatePaths: [], noPromptTemplates: true);

        self::assertContains('--no-prompt-templates', $argv);
    }

    public function testCombinedArgsPreserveOrder(): void
    {
        $argv = $this->startAndCaptureArgv(promptTemplatePaths: ['/a.md'], noPromptTemplates: true);

        $noIndex = array_search('--no-prompt-templates', $argv, true);
        $ptIndex = array_search('--prompt-template=/a.md', $argv, true);

        self::assertNotFalse($noIndex);
        self::assertNotFalse($ptIndex);
        self::assertLessThan($ptIndex, $noIndex, '--no-prompt-templates should come before --prompt-template');
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * @param list<string> $promptTemplatePaths
     *
     * @return list<string>
     */
    private function startAndCaptureArgv(array $promptTemplatePaths, bool $noPromptTemplates): array
    {
        $argvFile = $this->tmpDir.'/argv-'.bin2hex(random_bytes(4)).'.json';

        $promptConfig = new PromptTemplatesRuntimeConfig();
        $promptConfig->promptTemplatePaths = $promptTemplatePaths;
        $promptConfig->noPromptTemplates = $noPromptTemplates;

        // Inject --argv-dump=<path> into the executable command so the
        // fake controller script knows where to write the captured argv.
        $dumpFlag = '--argv-dump='.$argvFile;

        $runtimeConfig = new RuntimeProcessConfig(
            executableLocator: new class($this->fakeScript, $dumpFlag) implements AppExecutableLocator {
                public function __construct(
                    private string $fakeScript,
                    private string $dumpFlag,
                ) {}

                public function command(): array
                {
                    return [\PHP_BINARY, $this->fakeScript, $this->dumpFlag];
                }

                public function path(): string
                {
                    return $this->fakeScript;
                }
            },
            runtimeCwd: $this->tmpDir,
        );

        $client = new JsonlProcessAgentSessionClient(
            runtimeConfig: $runtimeConfig,
            promptTemplatesConfig: $promptConfig,
            logger: new TestLogger(),
        );

        $handle = $client->start(new StartRunRequest(
            prompt: 'hello',
            runId: 'test-run-'.bin2hex(random_bytes(4)),
        ));

        self::assertSame('running', $handle->status);

        // Wait for the process to finish writing the dump.
        $timeout = time() + 5;
        while (!is_file($argvFile) || 0 === filesize($argvFile)) {
            if (time() > $timeout) {
                self::fail('Timeout waiting for argv dump file at '.$argvFile);
            }
            usleep(50_000);
        }

        $json = file_get_contents($argvFile);
        self::assertNotFalse($json, 'argv dump file should be readable');
        $data = json_decode($json, true);
        self::assertIsArray($data, 'argv dump should be a JSON array');
        self::assertNotEmpty($data, 'argv dump should not be empty');

        return $data;
    }
}
