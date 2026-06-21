<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition\Tests;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionValidationException;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser;
use Ineersa\CodingAgent\Agent\Definition\AgentTypeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Definition\SystemPromptModeEnum;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AgentDefinitionParser covering valid definitions, representative
 * invalid definitions, default application, and actionable error messages
 * that include the file path and field name.
 *
 * Does NOT test trivial getters on DTOs or exhaustive enum-case lists.
 *
 * Test thesis: The parser must accept every valid combination the plan
 * enumerates and reject every invalid shape with actionable messages.
 */
final class AgentDefinitionParserTest extends TestCase
{
    private AgentDefinitionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AgentDefinitionParser(new AgentFrontmatterParser());
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function wrapContent(array $frontmatter, string $body = ''): string
    {
        $yaml = $this->toYaml($frontmatter);

        return "---\n{$yaml}---\n{$body}";
    }

    /**
     * Quick YAML emitter for simple key/value pairs (and nested arrays).
     *
     * @param array<string, mixed> $data
     */
    private function toYaml(array $data): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                if (array_is_list($value)) {
                    if ([] === $value) {
                        $lines[] = "{$key}: []";
                    } else {
                        $lines[] = "{$key}:";
                        foreach ($value as $item) {
                            if (\is_string($item)) {
                                $lines[] = "  - ".\json_encode($item, JSON_UNESCAPED_SLASHES);
                            } elseif (\is_bool($item)) {
                                $lines[] = '  - '.($item ? 'true' : 'false');
                            } else {
                                $lines[] = "  - {$item}";
                            }
                        }
                    }
                } else {
                    $lines[] = "{$key}:";
                    foreach ($value as $k => $v) {
                        if (\is_string($v)) {
                            $lines[] = "  {$k}: ".\json_encode($v, JSON_UNESCAPED_SLASHES);
                        } elseif (\is_bool($v)) {
                            $lines[] = "  {$k}: ".($v ? 'true' : 'false');
                        } elseif (\is_array($v)) {
                            $lines[] = "  {$k}:";
                            foreach ($v as $item) {
                                $lines[] = '    - '.json_encode($item, JSON_UNESCAPED_SLASHES);
                            }
                        } elseif (\is_int($v)) {
                            $lines[] = "  {$k}: {$v}";
                        } else {
                            $lines[] = "  {$k}: ".json_encode($v, JSON_UNESCAPED_SLASHES);
                        }
                    }
                }
            } elseif (\is_string($value)) {
                $lines[] = "{$key}: ".\json_encode($value, JSON_UNESCAPED_SLASHES);
            } elseif (\is_bool($value)) {
                $lines[] = "{$key}: ".($value ? 'true' : 'false');
            } elseif (\is_int($value)) {
                $lines[] = "{$key}: {$value}";
            } elseif (null === $value) {
                $lines[] = "{$key}: null";
            } else {
                $lines[] = "{$key}: {$value}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function parse(string $content, string $filePath = '/test/agent.md'): AgentDefinitionDTO
    {
        return $this->parser->parseContent($content, $filePath);
    }

    // -----------------------------------------------------------------
    //  Valid definitions
    // -----------------------------------------------------------------

    public function testFullValidDefinitionPreservesBody(): void
    {
        $content = $this->wrapContent([
            'name' => 'my-scout',
            'description' => 'A custom scout agent',
            'type' => 'scout',
            'tools' => ['read', 'ide_find_file', 'semantic-search'],
            'model' => 'deepseek/deepseek-v4-flash',
            'thinking' => 'low',
            'skills' => ['testing'],
            'inheritProjectContext' => true,
            'inheritAgentsMd' => false,
            'systemPromptMode' => 'append',
            'maxDepth' => 2,
            'backgroundAllowed' => true,
            'foregroundAllowed' => true,
            'parallelAllowed' => true,
            'disabled' => false,
            'handoffFormat' => 'my-handoff',
            'mcp' => [
                'mode' => 'specific',
                'tools' => ['context7__query-docs', 'websearch__search'],
            ],
        ], "You are a scout. Explore and report findings.\n");

        $dto = $this->parse($content);

        self::assertSame('my-scout', $dto->name);
        self::assertSame('A custom scout agent', $dto->description);
        self::assertSame(AgentTypeEnum::Scout, $dto->type);
        self::assertSame(['read', 'ide_find_file', 'semantic-search'], $dto->tools);
        self::assertSame('deepseek/deepseek-v4-flash', $dto->model);
        self::assertSame('low', $dto->thinking);
        self::assertSame(['testing'], $dto->skills);
        self::assertTrue($dto->inheritProjectContext);
        self::assertFalse($dto->inheritAgentsMd);
        self::assertSame(SystemPromptModeEnum::Append, $dto->systemPromptMode);
        self::assertSame(2, $dto->maxDepth);
        self::assertTrue($dto->backgroundAllowed);
        self::assertTrue($dto->foregroundAllowed);
        self::assertTrue($dto->parallelAllowed);
        self::assertFalse($dto->disabled);
        self::assertSame('my-handoff', $dto->handoffFormat);
        self::assertSame(McpAgentModeEnum::Specific, $dto->mcp->mode);
        self::assertSame(['context7__query-docs', 'websearch__search'], $dto->mcp->tools);
        self::assertSame('You are a scout. Explore and report findings.', $dto->instructions);
        self::assertSame('/test/agent.md', $dto->sourcePath);
        self::assertSame('/test', $dto->sourceDirectory);
    }

    public function testMinimalValidDefinitionAppliesDefaults(): void
    {
        $content = $this->wrapContent([
            'name' => 'minimal',
            'description' => 'Bare minimum',
            'type' => 'custom',
            'tools' => ['read'],
        ], 'Do the thing.');

        $dto = $this->parse($content);

        self::assertSame('minimal', $dto->name);
        self::assertSame('Bare minimum', $dto->description);
        self::assertSame(AgentTypeEnum::Custom, $dto->type);
        self::assertSame(['read'], $dto->tools);
        // Defaults
        self::assertNull($dto->model);
        self::assertNull($dto->thinking);
        self::assertSame([], $dto->skills);
        self::assertTrue($dto->inheritProjectContext);
        self::assertTrue($dto->inheritAgentsMd);
        self::assertSame(SystemPromptModeEnum::Replace, $dto->systemPromptMode);
        self::assertSame(1, $dto->maxDepth);
        self::assertTrue($dto->backgroundAllowed);
        self::assertTrue($dto->foregroundAllowed);
        self::assertFalse($dto->parallelAllowed);
        self::assertFalse($dto->disabled);
        self::assertNull($dto->handoffFormat);
        self::assertSame(McpAgentModeEnum::None, $dto->mcp->mode);
        self::assertSame([], $dto->mcp->tools);
        self::assertSame('Do the thing.', $dto->instructions);
    }

    public function testModesAllWithNoTools(): void
    {
        $content = $this->wrapContent([
            'name' => 'researcher',
            'description' => 'MCP all agent',
            'type' => 'researcher',
            'tools' => ['websearch__search'],
            'mcp' => ['mode' => 'all'],
        ]);

        $dto = $this->parse($content);
        self::assertSame(McpAgentModeEnum::All, $dto->mcp->mode);
        self::assertSame([], $dto->mcp->tools);
    }

    public function testThinkingOff(): void
    {
        $content = $this->wrapContent([
            'name' => 'no-think',
            'description' => 'Thinking off',
            'type' => 'worker',
            'tools' => ['bash'],
            'thinking' => 'off',
        ]);

        $dto = $this->parse($content);
        self::assertSame('off', $dto->thinking);
    }

    public function testThinkingXhigh(): void
    {
        $content = $this->wrapContent([
            'name' => 'deep-think',
            'description' => 'Deep thinker',
            'type' => 'reviewer',
            'tools' => ['read'],
            'thinking' => 'xhigh',
        ]);

        $dto = $this->parse($content);
        self::assertSame('xhigh', $dto->thinking);
    }

    public function testMaxDepthZero(): void
    {
        $content = $this->wrapContent([
            'name' => 'no-recursion',
            'description' => 'Cannot recurse',
            'type' => 'worker',
            'tools' => ['read'],
            'maxDepth' => 0,
        ]);

        $dto = $this->parse($content);
        self::assertSame(0, $dto->maxDepth);
    }

    public function testMaxDepthFive(): void
    {
        $content = $this->wrapContent([
            'name' => 'deep-recursion',
            'description' => 'Deep recursion',
            'type' => 'worker',
            'tools' => ['read'],
            'maxDepth' => 5,
        ]);

        $dto = $this->parse($content);
        self::assertSame(5, $dto->maxDepth);
    }

    public function testParallelAllowedFalseByDefault(): void
    {
        $content = $this->wrapContent([
            'name' => 'solo',
            'description' => 'Solo agent',
            'type' => 'scout',
            'tools' => ['read'],
        ]);

        $dto = $this->parse($content);
        self::assertFalse($dto->parallelAllowed);
    }

    public function testBodyWithMarkdownPreserved(): void
    {
        $content = $this->wrapContent([
            'name' => 'md-body',
            'description' => 'Markdown body test',
            'type' => 'scout',
            'tools' => ['read'],
        ], "## Instructions\n\n- Step 1\n- Step 2\n\n```php\necho 'hello';\n```\n");

        $dto = $this->parse($content);

        self::assertStringContainsString('## Instructions', $dto->instructions);
        self::assertStringContainsString('- Step 1', $dto->instructions);
        self::assertStringContainsString("```php", $dto->instructions);
        self::assertStringContainsString("echo 'hello';", $dto->instructions);
    }

    public function testThinkingNullExplicit(): void
    {
        $content = $this->wrapContent([
            'name' => 'null-think',
            'description' => 'Explicit null thinking',
            'type' => 'scout',
            'tools' => ['read'],
            'thinking' => null,
        ]);

        $dto = $this->parse($content);
        self::assertNull($dto->thinking);
    }

    public function testModelNullExplicit(): void
    {
        $content = $this->wrapContent([
            'name' => 'null-model',
            'description' => 'Explicit null model',
            'type' => 'scout',
            'tools' => ['read'],
            'model' => null,
        ]);

        $dto = $this->parse($content);
        self::assertNull($dto->model);
    }

    public function testClosesWithDots(): void
    {
        $content = "---\nname: dots-closer\ndescription: Uses dots\ntype: scout\ntools:\n  - read\n...\n\nBody after dots\n";

        $dto = $this->parse($content);
        self::assertSame('dots-closer', $dto->name);
        self::assertSame('Uses dots', $dto->description);
        self::assertSame('Body after dots', $dto->instructions);
    }

    // -----------------------------------------------------------------
    //  Invalid definitions — expect actionable exceptions
    // -----------------------------------------------------------------

    public function testMissingFrontmatterThrowsWithFilePath(): void
    {
        $content = "Just plain text, no frontmatter\n";

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"[^"]*some-path.md"[^"]*does not start/');

        $this->parser->parseContent($content, '/project/some-path.md');
    }

    public function testUnclosedFrontmatterThrowsWithFilePath(): void
    {
        $content = "---\nname: scout\nbut no closing delimiter\n";

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/[Nn]o closing delimiter/');

        $this->parser->parseContent($content, '/project/unclosed.md');
    }

    public function testInvalidYamlThrowsWithFilePath(): void
    {
        $content = "---\nname: \"unclosed\n---\nBody\n";

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/[Cc]ould not be parsed/');

        $this->parser->parseContent($content, '/project/bad-yaml.md');
    }

    public function testMissingNameThrows(): void
    {
        $content = $this->wrapContent([
            'description' => 'No name',
            'type' => 'scout',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"name" is required/');

        $this->parse($content, '/test/no-name.md');
    }

    public function testMissingDescriptionThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'no-desc',
            'type' => 'scout',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"description" is required/');

        $this->parse($content, '/test/no-desc.md');
    }

    public function testMissingTypeThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'no-type',
            'description' => 'No type',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"type" is required/');

        $this->parse($content, '/test/no-type.md');
    }

    public function testMissingToolsThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'no-tools',
            'description' => 'No tools',
            'type' => 'scout',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"tools" is required/');

        $this->parse($content, '/test/no-tools.md');
    }

    public function testUnknownFieldThrowsWithFieldNameAndFilePath(): void
    {
        $content = $this->wrapContent([
            'name' => 'scout',
            'description' => 'Test',
            'type' => 'scout',
            'tools' => ['read'],
            'unknownKey' => 'something',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "unknownKey"/');
        $this->expectExceptionMessageMatches('/\/test\/unknown-field\.md/');

        $this->parse($content, '/test/unknown-field.md');
    }

    public function testToolsNotAListThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-tools',
            'description' => 'Bad tools',
            'type' => 'scout',
            'tools' => 'read',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"tools" must be an array/');

        $this->parse($content, '/test/bad-tools.md');
    }

    public function testToolsEmptyListThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'empty-tools',
            'description' => 'Empty tools',
            'type' => 'scout',
            'tools' => [],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/non-empty list/');

        $this->parse($content, '/test/empty-tools.md');
    }

    public function testToolsContainsNonStringThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'non-string-tool',
            'description' => 'Non-string tool entry',
            'type' => 'scout',
            'tools' => ['read', 42],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"tools\[1\]" must be a string/');

        $this->parse($content, '/test/non-string-tool.md');
    }

    public function testToolsContainsEmptyStringThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'empty-tool',
            'description' => 'Empty tool string',
            'type' => 'scout',
            'tools' => ['read', ''],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"tools\[1\]" must not be empty/');

        $this->parse($content, '/test/empty-tool.md');
    }

    public function testInvalidTypeEnumThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-type',
            'description' => 'Bad type',
            'type' => 'flying-car',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"type" must be one of/');
        $this->expectExceptionMessageMatches('/flying-car/');

        $this->parse($content, '/test/bad-type.md');
    }

    public function testInvalidThinkingEnumThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-think',
            'description' => 'Bad thinking',
            'type' => 'scout',
            'tools' => ['read'],
            'thinking' => 'extreme',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"thinking" must be one of/');
        $this->expectExceptionMessageMatches('/extreme/');

        $this->parse($content, '/test/bad-think.md');
    }

    public function testInvalidMcpModeEnumThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-mcp',
            'description' => 'Bad MCP mode',
            'type' => 'scout',
            'tools' => ['read'],
            'mcp' => ['mode' => 'sometimes'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"mcp.mode" must be one of/');

        $this->parse($content, '/test/bad-mcp.md');
    }

    public function testMcpSpecificWithoutToolsThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'specific-no-tools',
            'description' => 'Specific mode without tools',
            'type' => 'scout',
            'tools' => ['read'],
            'mcp' => ['mode' => 'specific'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"specific" but "mcp.tools" is empty or missing/');

        $this->parse($content, '/test/specific-no-tools.md');
    }

    public function testMcpToolsWithNoneModeThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'none-with-tools',
            'description' => 'None mode with tools',
            'type' => 'scout',
            'tools' => ['read'],
            'mcp' => ['mode' => 'none', 'tools' => ['context7__query-docs']],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"mcp.tools" is set but "mcp.mode" is "none"/');

        $this->parse($content, '/test/none-with-tools.md');
    }

    public function testMcpToolsWithAllModeThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'all-with-tools',
            'description' => 'All mode with tools',
            'type' => 'scout',
            'tools' => ['read'],
            'mcp' => ['mode' => 'all', 'tools' => ['context7__query-docs']],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"mcp.tools" is set but "mcp.mode" is "all"/');

        $this->parse($content, '/test/all-with-tools.md');
    }

    public function testBoolFieldRejectsString(): void
    {
        $content = $this->wrapContent([
            'name' => 'string-bool',
            'description' => 'String for bool',
            'type' => 'scout',
            'tools' => ['read'],
            'inheritProjectContext' => 'yes',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"inheritProjectContext" must be a boolean/');

        $this->parse($content, '/test/string-bool.md');
    }

    public function testParallelAllowedRejectsString(): void
    {
        $content = $this->wrapContent([
            'name' => 'string-parallel',
            'description' => 'String for bool',
            'type' => 'scout',
            'tools' => ['read'],
            'parallelAllowed' => 'true',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"parallelAllowed" must be a boolean/');

        $this->parse($content, '/test/string-parallel.md');
    }

    public function testDisabledRejectsInt(): void
    {
        $content = $this->wrapContent([
            'name' => 'int-disabled',
            'description' => 'Int for disabled',
            'type' => 'scout',
            'tools' => ['read'],
            'disabled' => 1,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"disabled" must be a boolean/');

        $this->parse($content, '/test/int-disabled.md');
    }

    public function testBothLaunchModesFalseThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'unlaunchable',
            'description' => 'Cannot launch',
            'type' => 'scout',
            'tools' => ['read'],
            'backgroundAllowed' => false,
            'foregroundAllowed' => false,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/cannot both be false/');

        $this->parse($content, '/test/unlaunchable.md');
    }

    public function testInvalidNameFormatThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'Invalid Name!',
            'description' => 'Bad name format',
            'type' => 'scout',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/must be lowercase alphanumeric/');

        $this->parse($content, '/test/bad-name.md');
    }

    public function testNameStartingWithDigitThrows(): void
    {
        $content = $this->wrapContent([
            'name' => '2fast',
            'description' => 'Starts with digit',
            'type' => 'scout',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/must be lowercase alphanumeric/');

        $this->parse($content, '/test/2fast.md');
    }

    public function testNameStartingWithHyphenThrows(): void
    {
        $content = $this->wrapContent([
            'name' => '-bad',
            'description' => 'Starts with hyphen',
            'type' => 'scout',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/must be lowercase alphanumeric/');

        $this->parse($content, '/test/hyphen-bad.md');
    }

    public function testNameTooLongThrows(): void
    {
        $content = $this->wrapContent([
            'name' => str_repeat('a', 49),
            'description' => 'Too long name',
            'type' => 'scout',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/must be lowercase alphanumeric/');

        $this->parse($content, '/test/long-name.md');
    }

    public function testMaxDepthTooLowThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-depth',
            'description' => 'Depth too low',
            'type' => 'scout',
            'tools' => ['read'],
            'maxDepth' => -1,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"maxDepth" must be between 0 and 5, got -1/');

        $this->parse($content, '/test/bad-depth.md');
    }

    public function testMaxDepthTooHighThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-depth-high',
            'description' => 'Depth too high',
            'type' => 'scout',
            'tools' => ['read'],
            'maxDepth' => 6,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"maxDepth" must be between 0 and 5, got 6/');

        $this->parse($content, '/test/bad-depth-high.md');
    }

    public function testMaxDepthRejectsString(): void
    {
        $content = $this->wrapContent([
            'name' => 'string-depth',
            'description' => 'String depth',
            'type' => 'scout',
            'tools' => ['read'],
            'maxDepth' => '3',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"maxDepth" must be an integer/');

        $this->parse($content, '/test/string-depth.md');
    }

    public function testMcpFieldRejectsStringInsteadOfObject(): void
    {
        $content = $this->wrapContent([
            'name' => 'string-mcp',
            'description' => 'String instead of MCP object',
            'type' => 'scout',
            'tools' => ['read'],
            'mcp' => 'none',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"mcp" must be an object/');

        $this->parse($content, '/test/string-mcp.md');
    }

    public function testSkillsRejectsNonArray(): void
    {
        $content = $this->wrapContent([
            'name' => 'string-skills',
            'description' => 'String skills',
            'type' => 'scout',
            'tools' => ['read'],
            'skills' => 'testing',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"skills" must be an array/');

        $this->parse($content, '/test/string-skills.md');
    }

    public function testDescriptionEmptyStringThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'empty-desc',
            'description' => '',
            'type' => 'scout',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"description" must not be empty/');

        $this->parse($content, '/test/empty-desc.md');
    }

    public function testDescriptionWhitespaceOnlyThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'ws-desc',
            'description' => '   ',
            'type' => 'scout',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"description" must not be empty/');

        $this->parse($content, '/test/ws-desc.md');
    }

    public function testUnknownMcpSubFieldThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'unknown-mcp-field',
            'description' => 'Unknown MCP sub field',
            'type' => 'scout',
            'tools' => ['read'],
            'mcp' => ['mode' => 'none', 'extraStuff' => true],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "mcp.extraStuff"/');

        $this->parse($content, '/test/unknown-mcp.md');
    }

    public function testInvalidSystemPromptModeThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-spm',
            'description' => 'Bad system prompt mode',
            'type' => 'scout',
            'tools' => ['read'],
            'systemPromptMode' => 'hybrid',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"systemPromptMode" must be one of/');

        $this->parse($content, '/test/bad-spm.md');
    }

    // -----------------------------------------------------------------
    //  Edge-case valid inputs
    // -----------------------------------------------------------------

    public function testHyphenatedName(): void
    {
        $content = $this->wrapContent([
            'name' => 'my-custom-agent-2',
            'description' => 'Hyphenated',
            'type' => 'custom',
            'tools' => ['read'],
        ]);

        $dto = $this->parse($content);
        self::assertSame('my-custom-agent-2', $dto->name);
    }
}
