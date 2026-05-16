<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use PHPUnit\Framework\TestCase;

class HomeSettingsWriterTest extends TestCase
{
    private string $tmpDir;
    private HomeSettingsWriter $writer;
    private string $settingsFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield_writer_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
        $this->settingsFile = $this->tmpDir.'/settings.yaml';
        $this->writer = new HomeSettingsWriter();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /**
     * @param-out string $path
     */
    private function writeSettingsFile(string $content): void
    {
        file_put_contents($this->settingsFile, $content);
    }

    private function readSettingsFile(): string
    {
        $c = file_get_contents($this->settingsFile);

        \assert(false !== $c);

        return $c;
    }

    private function yamlParse(): array
    {
        $result = \Symfony\Component\Yaml\Yaml::parseFile($this->settingsFile);

        return \is_array($result) ? $result : [];
    }

    // ── writeDefaultModel ──────────────────────────────────────────

    public function testWriteDefaultModelRepresentsNewModel(): void
    {
        $this->writeSettingsFile("ai:\n    default_reasoning: medium\n");
        $this->writer->writeDefaultModel($this->settingsFile, 'zai/glm-5.1');

        $parsed = $this->yamlParse();
        self::assertSame('zai/glm-5.1', $parsed['ai']['default_model'] ?? null);
    }

    public function testWriteDefaultModelUpdatesExistingActive(): void
    {
        $this->writeSettingsFile("ai:\n    default_model: deepseek/deepseek-v4-pro\n    default_reasoning: medium\n");
        $this->writer->writeDefaultModel($this->settingsFile, 'zai/glm-5.1');

        $parsed = $this->yamlParse();
        self::assertSame('zai/glm-5.1', $parsed['ai']['default_model'] ?? null);
        self::assertSame('medium', $parsed['ai']['default_reasoning'] ?? null);
    }

    public function testWriteDefaultModelUncommentsIfCommented(): void
    {
        $this->writeSettingsFile("ai:\n# default_model: old-model\n");
        $this->writer->writeDefaultModel($this->settingsFile, 'deepseek/deepseek-v4-pro');

        $parsed = $this->yamlParse();
        self::assertSame('deepseek/deepseek-v4-pro', $parsed['ai']['default_model'] ?? null);
    }

    public function testWriteDefaultModelAppendsAiSectionWhenMissing(): void
    {
        $this->writeSettingsFile("tui:\n    theme: cyberpunk\n");
        $this->writer->writeDefaultModel($this->settingsFile, 'llama_cpp/flash');

        $parsed = $this->yamlParse();
        self::assertSame('llama_cpp/flash', $parsed['ai']['default_model'] ?? null);
        self::assertSame('cyberpunk', $parsed['tui']['theme'] ?? null);
    }

    public function testWriteDefaultModelPreservesCommentsAndUnrelatedKeys(): void
    {
        $content = "# My settings\nai:\n    # important comment\n    default_reasoning: medium\n    # another comment\n";
        $this->writeSettingsFile($content);
        $this->writer->writeDefaultModel($this->settingsFile, 'zai/glm-5v-turbo');

        $result = $this->readSettingsFile();
        self::assertStringContainsString('# My settings', $result);
        self::assertStringContainsString('# important comment', $result);
        self::assertStringContainsString('# another comment', $result);
        self::assertStringContainsString('default_reasoning: medium', $result);
        self::assertStringContainsString('default_model: zai/glm-5v-turbo', $result);
    }

    public function testWriteDefaultModelPreservesCommentsWhenAiSectionIsCommentedProviders(): void
    {
        $content = "# My settings\nai:\n    # providers:\n    #     deepseek:\n    default_model: deepseek/deepseek-v4-pro\n";
        $this->writeSettingsFile($content);
        $this->writer->writeDefaultModel($this->settingsFile, 'zai/glm-5.1');

        $result = $this->readSettingsFile();
        self::assertStringContainsString('# My settings', $result);
        self::assertStringContainsString('# providers:', $result);
        self::assertStringContainsString('default_model: zai/glm-5.1', $result);
    }

    // ── writeDefaultReasoning ──────────────────────────────────────

    public function testWriteDefaultReasoningUpdatesExisting(): void
    {
        $this->writeSettingsFile("ai:\n    default_model: deepseek/deepseek-v4-pro\n    default_reasoning: medium\n");
        $this->writer->writeDefaultReasoning($this->settingsFile, 'high');

        $parsed = $this->yamlParse();
        self::assertSame('high', $parsed['ai']['default_reasoning'] ?? null);
        self::assertSame('deepseek/deepseek-v4-pro', $parsed['ai']['default_model'] ?? null);
    }

    public function testWriteDefaultReasoningUncommentsIfCommented(): void
    {
        $this->writeSettingsFile("ai:\n# default_model: deepseek/deepseek-v4-pro\n#     default_reasoning: low\n");
        $this->writer->writeDefaultReasoning($this->settingsFile, 'xhigh');

        $parsed = $this->yamlParse();
        self::assertSame('xhigh', $parsed['ai']['default_reasoning'] ?? null);
    }

    public function testWriteDefaultReasoningInsertsWhenAbsent(): void
    {
        $this->writeSettingsFile("ai:\n    default_model: deepseek/deepseek-v4-pro\n");
        $this->writer->writeDefaultReasoning($this->settingsFile, 'minimal');

        $parsed = $this->yamlParse();
        self::assertSame('minimal', $parsed['ai']['default_reasoning'] ?? null);
    }

    public function testWriteDefaultReasoningPreservesComments(): void
    {
        $content = "# top comment\nai:\n    # model pick\n    default_model: deepseek/deepseek-v4-pro\n";
        $this->writeSettingsFile($content);
        $this->writer->writeDefaultReasoning($this->settingsFile, 'low');

        $result = $this->readSettingsFile();
        self::assertStringContainsString('# top comment', $result);
        self::assertStringContainsString('# model pick', $result);
        self::assertStringContainsString('default_model: deepseek/deepseek-v4-pro', $result);
        self::assertStringContainsString('default_reasoning: low', $result);
    }

    // ── YAML quoting / edge cases ──────────────────────────────────

    public function testYamlQuotingColonInValue(): void
    {
        $this->writeSettingsFile("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel($this->settingsFile, 'model:with:colons');

        $result = $this->readSettingsFile();
        self::assertStringContainsString("default_model: 'model:with:colons'", $result);
    }

    public function testYamlQuotingHashInValue(): void
    {
        $this->writeSettingsFile("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel($this->settingsFile, 'model#tag');

        $result = $this->readSettingsFile();
        self::assertStringContainsString("default_model: 'model#tag'", $result);
    }

    public function testYamlNoQuoteForDottedValues(): void
    {
        $this->writeSettingsFile("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel($this->settingsFile, 'zai/glm-5.1');

        $result = $this->readSettingsFile();
        self::assertStringContainsString('default_model: zai/glm-5.1', $result);
        self::assertStringNotContainsString("'zai/glm-5.1'", $result);
    }

    public function testYamlEmptyValueQuoted(): void
    {
        $this->writeSettingsFile("ai:\n    default_model: old\n");
        $this->writer->writeDefaultModel($this->settingsFile, '');

        $result = $this->readSettingsFile();
        self::assertStringContainsString("default_model: ''", $result);
    }

    // ── Error paths ────────────────────────────────────────────────

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->writer->writeDefaultModel('/nonexistent/path.yml', 'deepseek/deepseek-v4-pro');
    }

    public function testThrowsOnUnreadableFile(): void
    {
        $file = $this->tmpDir.'/unreadable.yaml';
        file_put_contents($file, 'ai: {}');
        chmod($file, 0o000);

        try {
            $this->expectException(\RuntimeException::class);
            $this->writer->writeDefaultModel($file, 'deepseek/deepseek-v4-pro');
        } finally {
            chmod($file, 0o644);
        }
    }

    public function testThrowsOnUnwritableFile(): void
    {
        $file = $this->tmpDir.'/readonly.yaml';
        file_put_contents($file, 'ai: {}');
        chmod($file, 0o444);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not writable');
            $this->writer->writeDefaultModel($file, 'deepseek/deepseek-v4-pro');
        } finally {
            chmod($file, 0o644);
        }
    }

    // ── Helper ─────────────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                chmod($path, 0o644); // ensure deletable
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
