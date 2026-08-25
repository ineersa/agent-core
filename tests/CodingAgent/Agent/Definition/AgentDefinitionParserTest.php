<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Definition;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionValidationException;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser;
use Ineersa\CodingAgent\Agent\Definition\SystemPromptModeEnum;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Tests for AgentDefinitionParser covering valid definitions, representative
 * invalid definitions, default application, and actionable error messages
 * that include the file path and field/property-path.
 *
 * Uses Symfony Serializer + Validator (not manual is_* checks).
 *
 * Test thesis: The parser must accept every valid combination the plan
 * enumerates and reject every invalid shape with actionable messages.
 */
final class AgentDefinitionParserTest extends TestCase
{
    private AgentDefinitionParser $parser;
    private SerializerInterface $serializer;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        // Production-mirroring Serializer (MetadataAware + camel_case_to_snake_case).
        [$this->serializer, $this->validator] = AttributeSerializerValidatorTestFactory::create();

        $this->parser = new AgentDefinitionParser(
            frontmatterParser: new AgentFrontmatterParser(new MarkdownFrontmatterExtractor()),
            denormalizer: $this->serializer,
            validator: $this->validator,
        );
    }

    public function testFullValidDefinitionPreservesBody(): void
    {
        $content = $this->wrapContent($this->validFrontmatter(), "You are a scout. Explore and report findings.\n");

        $dto = $this->rawParse($content);

        $this->assertSame('my-scout', $dto->name);
        $this->assertSame('A custom scout agent', $dto->description);
        $this->assertSame(['read', 'ide_find_file', 'semantic-search'], $dto->tools);
        $this->assertSame('deepseek/deepseek-v4-flash', $dto->model);
        $this->assertSame('low', $dto->thinking);
        $this->assertSame(['testing'], $dto->skills);
        $this->assertFalse($dto->inheritProjectContext);
        $this->assertSame(SystemPromptModeEnum::Append, $dto->systemPromptMode);
        $this->assertTrue($dto->parallelAllowed);
        $this->assertSame('You are a scout. Explore and report findings.', $dto->instructions);
        $this->assertSame('/test/agent.md', $dto->sourcePath);
        $this->assertSame('/test', $dto->sourceDirectory);
    }

    public function testMinimalValidDefinitionAppliesDefaults(): void
    {
        $dto = $this->parse([
            'name' => 'minimal',
            'description' => 'Bare minimum',
            'tools' => ['read'],
        ], '/test/minimal.md');

        $this->assertSame('minimal', $dto->name);
        $this->assertSame('Bare minimum', $dto->description);
        $this->assertSame(['read'], $dto->tools);
        $this->assertNull($dto->model);
        $this->assertNull($dto->thinking);
        $this->assertSame([], $dto->skills);
        $this->assertTrue($dto->inheritProjectContext);
        $this->assertSame(SystemPromptModeEnum::Replace, $dto->systemPromptMode);
        $this->assertTrue($dto->parallelAllowed);
    }

    public function testThinkingOff(): void
    {
        $dto = $this->parse([
            'name' => 'no-think',
            'description' => 'Thinking off',
            'tools' => ['bash'],
            'thinking' => 'off',
        ]);

        $this->assertSame('off', $dto->thinking);
    }

    public function testThinkingXhigh(): void
    {
        $dto = $this->parse([
            'name' => 'deep-think',
            'description' => 'Deep thinker',
            'tools' => ['read'],
            'thinking' => 'xhigh',
        ]);

        $this->assertSame('xhigh', $dto->thinking);
    }

    public function testParallelAllowedTrueByDefault(): void
    {
        $dto = $this->parse([
            'name' => 'solo',
            'description' => 'Solo agent',
            'tools' => ['read'],
        ]);

        $this->assertTrue($dto->parallelAllowed);
    }

    public function testParallelAllowedExplicitFalse(): void
    {
        $dto = $this->parse([
            'name' => 'solo',
            'description' => 'Solo agent',
            'tools' => ['read'],
            'parallelAllowed' => false,
        ]);

        $this->assertFalse($dto->parallelAllowed);
    }

    public function testBodyWithMarkdownPreserved(): void
    {
        $content = $this->wrapContent([
            'name' => 'md-body',
            'description' => 'Markdown body test',
            'tools' => ['read'],
        ], "## Instructions\n\n- Step 1\n- Step 2\n\n```php\necho 'hello';\n```\n");

        $dto = $this->rawParse($content);

        $this->assertStringContainsString('## Instructions', $dto->instructions);
        $this->assertStringContainsString('- Step 1', $dto->instructions);
        $this->assertStringContainsString('```php', $dto->instructions);
        $this->assertStringContainsString("echo 'hello';", $dto->instructions);
    }

    public function testThinkingNullExplicit(): void
    {
        $dto = $this->parse([
            'name' => 'null-think',
            'description' => 'Explicit null thinking',
            'tools' => ['read'],
            'thinking' => null,
        ]);

        $this->assertNull($dto->thinking);
    }

    public function testModelNullExplicit(): void
    {
        $dto = $this->parse([
            'name' => 'null-model',
            'description' => 'Explicit null model',
            'tools' => ['read'],
            'model' => null,
        ]);

        $this->assertNull($dto->model);
    }

    public function testClosesWithDots(): void
    {
        $content = "---\nname: dots-closer\ndescription: Uses dots\ntools:\n  - read\n...\n\nBody after dots\n";

        $dto = $this->rawParse($content);
        $this->assertSame('dots-closer', $dto->name);
        $this->assertSame('Uses dots', $dto->description);
        $this->assertSame('Body after dots', $dto->instructions);
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
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"name" is required/');

        $this->parser->parseContent($content, '/test/no-name.md');
    }

    public function testMissingDescriptionThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'no-desc',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"description".*required/');

        $this->parser->parseContent($content, '/test/no-desc.md');
    }

    public function testTypeIsRejectedAsUnknownField(): void
    {
        $content = $this->wrapContent([
            'name' => 'has-type',
            'description' => 'Has type field',
            'tools' => ['read'],
            'type' => 'scout',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field/');
        $this->expectExceptionMessageMatches('/type/');

        $this->parser->parseContent($content, '/test/has-type.md');
    }

    public function testMissingToolsStaysNullForInheritAll(): void
    {
        $dto = $this->parse([
            'name' => 'no-tools',
            'description' => 'No tools',
        ], '/test/no-tools.md');

        $this->assertNull($dto->tools);
    }

    public function testUnknownFieldThrowsWithFieldNameAndFilePath(): void
    {
        $content = $this->wrapContent([
            'name' => 'scout',
            'description' => 'Test',
            'tools' => ['read'],
            'unknownKey' => 'something',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "unknownKey"/');
        $this->expectExceptionMessageMatches('/\/test\/unknown-field\.md/');

        $this->parser->parseContent($content, '/test/unknown-field.md');
    }

    public function testToolsCommaSeparatedStringIsNormalized(): void
    {
        $dto = $this->parse([
            'name' => 'reviewer-like',
            'description' => 'Comma-separated tools',
            'tools' => 'read, grep, find, ls, bash',
        ]);

        $this->assertSame(['read', 'grep', 'find', 'ls', 'bash'], $dto->tools);
    }

    public function testToolsCommaSeparatedStringWithoutSpaces(): void
    {
        $dto = $this->parse([
            'name' => 'browser-like',
            'description' => 'Tight comma list',
            'tools' => 'read,bash,grep,find,ls',
        ]);

        $this->assertSame(['read', 'bash', 'grep', 'find', 'ls'], $dto->tools);
    }

    public function testSkillsStringIsNormalized(): void
    {
        $dto = $this->parse([
            'name' => 'browser-like',
            'description' => 'String skills',
            'tools' => ['read'],
            'skills' => 'playwright-cli',
        ]);

        $this->assertSame(['playwright-cli'], $dto->skills);
    }

    public function testRepresentativeUserAgentFrontmatterShapes(): void
    {
        $scout = $this->rawParse('---
name: scout
description: Fast codebase recon that returns compressed context for handoff
model: deepseek/deepseek-v4-flash
---
Body
', '/home/user/.agents/scout.md');
        $this->assertSame('scout', $scout->name);
        $this->assertNull($scout->tools);

        $reviewer = $this->rawParse('---
name: reviewer
description: Senior code reviewer
tools: read, grep, find, ls, bash
---
Body
', '/home/user/.agents/reviewer.md');
        $this->assertSame('reviewer', $reviewer->name);
        $this->assertContains('read', $reviewer->tools);
        $this->assertContains('bash', $reviewer->tools);
    }

    public function testToolsEmptyListThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'empty-tools',
            'description' => 'Empty tools',
            'tools' => [],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/non-empty/');

        $this->parser->parseContent($content, '/test/empty-tools.md');
    }

    public function testToolsContainsNonStringThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'non-string-tool',
            'description' => 'Non-string tool entry',
            'tools' => ['read', 42],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/tools\[1\].*must be of type string/');

        $this->parser->parseContent($content, '/test/non-string-tool.md');
    }

    public function testToolsContainsEmptyStringThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'empty-tool',
            'description' => 'Empty tool string',
            'tools' => ['read', ''],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/tools\[1\].*must not be empty/');

        $this->parser->parseContent($content, '/test/empty-tool.md');
    }

    public function testInvalidThinkingEnumThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-think',
            'description' => 'Bad thinking',
            'tools' => ['read'],
            'thinking' => 'extreme',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/thinking.*must be one of.*off.*minimal/');

        $this->parser->parseContent($content, '/test/bad-think.md');
    }

    public function testBoolFieldRejectsNonCoercibleValue(): void
    {
        // Serializer with strict type enforcement rejects arrays for bool properties.
        $content = $this->wrapContent([
            'name' => 'string-bool',
            'description' => 'Array for bool',
            'tools' => ['read'],
            'inheritProjectContext' => [],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/must be of type bool/');

        $this->parser->parseContent($content, '/test/string-bool.md');
    }

    public function testParallelAllowedRejectsNonCoercibleValue(): void
    {
        // Serializer with strict type enforcement rejects arrays for bool properties.
        $content = $this->wrapContent([
            'name' => 'string-parallel',
            'description' => 'Array for bool',
            'tools' => ['read'],
            'parallelAllowed' => [],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/must be of type bool/');

        $this->parser->parseContent($content, '/test/string-parallel.md');
    }

    public function testDisabledIsUnknownField(): void
    {
        $content = $this->wrapContent([
            'name' => 'legacy-disabled',
            'description' => 'Removed field',
            'tools' => ['read'],
            'disabled' => true,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "disabled"/');

        $this->parser->parseContent($content, '/test/legacy-disabled.md');
    }

    public function testRemovedForegroundAllowedIsUnknownField(): void
    {
        $content = $this->wrapContent([
            'name' => 'legacy-foreground',
            'description' => 'Removed field',
            'tools' => ['read'],
            'foregroundAllowed' => true,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "foregroundAllowed"/');

        $this->parser->parseContent($content, '/test/legacy-foreground.md');
    }

    public function testInvalidNameFormatThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'Invalid Name!',
            'description' => 'Bad name format',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase alphanumeric/');

        $this->parser->parseContent($content, '/test/bad-name.md');
    }

    public function testNameStartingWithDigitThrows(): void
    {
        $content = $this->wrapContent([
            'name' => '2fast',
            'description' => 'Starts with digit',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase alphanumeric/');

        $this->parser->parseContent($content, '/test/2fast.md');
    }

    public function testNameStartingWithHyphenThrows(): void
    {
        $content = $this->wrapContent([
            'name' => '-bad',
            'description' => 'Starts with hyphen',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase alphanumeric/');

        $this->parser->parseContent($content, '/test/hyphen-bad.md');
    }

    public function testNameTooLongThrows(): void
    {
        $content = $this->wrapContent([
            'name' => str_repeat('a', 49),
            'description' => 'Too long name',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase alphanumeric/');

        $this->parser->parseContent($content, '/test/long-name.md');
    }

    public function testRemovedMaxDepthIsUnknownField(): void
    {
        $content = $this->wrapContent([
            'name' => 'legacy-depth',
            'description' => 'Removed field',
            'tools' => ['read'],
            'maxDepth' => 1,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "maxDepth"/');

        $this->parser->parseContent($content, '/test/legacy-depth.md');
    }

    public function testRemovedLegacyTopLevelMcpIsUnknownField(): void
    {
        $content = $this->wrapContent([
            'name' => 'legacy-mcp',
            'description' => 'Removed field',
            'tools' => ['read'],
            'mcp' => ['mode' => 'all'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "mcp"/');

        $this->parser->parseContent($content, '/test/legacy-mcp.md');
    }

    public function testSingularSkillAliasIsUnknownField(): void
    {
        $content = $this->wrapContent([
            'name' => 'legacy-skill',
            'description' => 'Removed alias',
            'tools' => ['read'],
            'skill' => 'testing',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "skill"/');

        $this->parser->parseContent($content, '/test/legacy-skill.md');
    }

    public function testSkillsStringScalarIsNormalized(): void
    {
        $dto = $this->parse([
            'name' => 'string-skills',
            'description' => 'String skills',
            'tools' => ['read'],
            'skills' => 'testing',
        ]);

        $this->assertSame(['testing'], $dto->skills);
    }

    public function testDescriptionEmptyStringThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'empty-desc',
            'description' => '',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/description.*required/');

        $this->parser->parseContent($content, '/test/empty-desc.md');
    }

    public function testDescriptionWhitespaceOnlyThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'ws-desc',
            'description' => '   ',
            'tools' => ['read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/description.*required/');

        $this->parser->parseContent($content, '/test/ws-desc.md');
    }

    public function testInvalidSystemPromptModeThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-spm',
            'description' => 'Bad system prompt mode',
            'tools' => ['read'],
            'systemPromptMode' => 'hybrid',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/systemPromptMode.*must be one of/');

        $this->parser->parseContent($content, '/test/bad-spm.md');
    }

    // -----------------------------------------------------------------
    //  Edge-case valid inputs
    // -----------------------------------------------------------------

    public function testHyphenatedName(): void
    {
        $dto = $this->parse([
            'name' => 'my-custom-agent-2',
            'description' => 'Hyphenated',
            'tools' => ['read'],
        ]);

        $this->assertSame('my-custom-agent-2', $dto->name);
    }

    // -----------------------------------------------------------------
    //  Reviewer fixes: closing delimiter, BOM, explicit nulls, whitespace
    // -----------------------------------------------------------------

    public function testBomStrippedBeforeFrontmatterCheck(): void
    {
        $raw = "\xEF\xBB\xBF---\nname: bom-stripped\ndescription: BOM test\ntools:\n  - read\n---\nbody\n";

        $dto = $this->rawParse($raw);
        $this->assertSame('bom-stripped', $dto->name);
        $this->assertSame('body', $dto->instructions);
    }

    public function testClosingDelimiterNotMatchedMidToken(): void
    {
        // The opening delimiter must be on its own line ("---" followed by newline
        // or EOF).  A first-line of "---title" is NOT treated as opening.
        $raw = "---title\nname: should-fail\n";

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/does not start/');

        $this->parser->parseContent($raw, '/test/opening-mid-token.md');
    }

    public function testParseFileWithRealFilePopulatesPathAndDirectory(): void
    {
        $tmpDir = TestDirectoryIsolation::createProjectTempDir();
        try {
            $filePath = $tmpDir.'/test-agent.md';
            $raw = "---\nname: real-file\ndescription: Real file test\ntools:\n  - read\n---\nbody\n";
            file_put_contents($filePath, $raw);

            $dto = $this->parser->parseFile($filePath);
            $this->assertSame('real-file', $dto->name);
            $this->assertSame($filePath, $dto->sourcePath);
            $this->assertSame($tmpDir, $dto->sourceDirectory);
            $this->assertSame('body', $dto->instructions);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }

    public function testToolsEntryWithLeadingWhitespaceRejected(): void
    {
        // Leading whitespace in tool entries must be rejected, not silently trimmed.
        $content = $this->wrapContent([
            'name' => 'ws-tool',
            'description' => 'Whitespace tool',
            'tools' => ['  read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/tools\[0\].*must not have leading or trailing whitespace/');

        $this->parser->parseContent($content, '/test/ws-tool.md');
    }

    public function testToolsEntryWithTrailingWhitespaceRejected(): void
    {
        // Trailing whitespace in tool entries must be rejected, not silently trimmed.
        $content = $this->wrapContent([
            'name' => 'ws-tool-trailing',
            'description' => 'Trailing whitespace tool',
            'tools' => ['read  '],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/tools\[0\].*must not have leading or trailing whitespace/');

        $this->parser->parseContent($content, '/test/ws-tool-trailing.md');
    }

    public function testSkillsEntryWhitespaceOnlyRejected(): void
    {
        // Whitespace-only entries trigger the leading/trailing whitespace Regex.
        $content = $this->wrapContent([
            'name' => 'skills-ws-only',
            'description' => 'Skills whitespace-only',
            'tools' => ['read'],
            'skills' => ['   '],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/skills\[0\].*must not have leading or trailing whitespace/');

        $this->parser->parseContent($content, '/test/skills-ws-only.md');
    }

    public function testSkillsEntryWithSurroundingWhitespaceRejected(): void
    {
        // Surrounding whitespace in skills entries must be rejected.
        $content = $this->wrapContent([
            'name' => 'skills-ws-surround',
            'description' => 'Skills with surrounding whitespace',
            'tools' => ['read'],
            'skills' => ['  testing  '],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/skills\[0\].*must not have leading or trailing whitespace/');

        $this->parser->parseContent($content, '/test/skills-ws-surround.md');
    }

    public function testParseFileThrowsForNonExistentFile(): void
    {
        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/not found or not readable/');

        $this->parser->parseFile('/nonexistent/definitely-not-there.md');
    }

    // -----------------------------------------------------------------
    //  New tests for Serializer/Validator-specific behaviors
    // -----------------------------------------------------------------

    public function testSerializerRejectsUnknownTopLevelField(): void
    {
        $content = $this->wrapContent([
            'name' => 'guard',
            'description' => 'Guard',
            'tools' => ['read'],
            'somethingUnexpected' => 'bad',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "somethingUnexpected"/');

        $this->parser->parseContent($content, '/test/extra.md');
    }

    public function testNameLeadingWhitespaceTrimmed(): void
    {
        $dto = $this->parse([
            'name' => '  trimmed-name  ',
            'description' => 'Name with surrounding whitespace',
            'tools' => ['read'],
        ]);

        $this->assertSame('trimmed-name', $dto->name);
    }

    public function testDescriptionLeadingWhitespaceTrimmed(): void
    {
        $dto = $this->parse([
            'name' => 'desc-trim',
            'description' => '  trimmed description  ',
            'tools' => ['read'],
        ]);

        $this->assertSame('trimmed description', $dto->description);
    }

    // -----------------------------------------------------------------
    //  Coercion rejection: Serializer MUST reject type mismatches
    // -----------------------------------------------------------------

    public function testInheritProjectContextRejectsQuotedYesString(): void
    {
        // "yes" in YAML quotes is a string, not the YAML boolean true.
        // Strict type enforcement must reject string for bool.
        $content = $this->wrapContent([
            'name' => 'coerce-bool',
            'description' => 'String yes for bool',
            'tools' => ['read'],
            'inheritProjectContext' => 'yes',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/inheritProjectContext.*must be of type bool/');

        $this->parser->parseContent($content, '/test/coerce-bool-yes.md');
    }

    public function testParallelAllowedRejectsQuotedFalseString(): void
    {
        // "false" in YAML quotes is a string, not the YAML boolean false.
        // Strict type enforcement must reject string for bool.
        $content = $this->wrapContent([
            'name' => 'coerce-parallel',
            'description' => 'String false for bool',
            'tools' => ['read'],
            'parallelAllowed' => 'false',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/parallelAllowed.*must be of type bool/');

        $this->parser->parseContent($content, '/test/coerce-parallel-false.md');
    }

    public function testRemovedInheritAgentsMdIsUnknownField(): void
    {
        $content = $this->wrapContent([
            'name' => 'legacy-inherit-agents',
            'description' => 'Removed field',
            'tools' => ['read'],
            'inheritAgentsMd' => true,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "inheritAgentsMd"/');

        $this->parser->parseContent($content, '/test/legacy-inherit-agents.md');
    }

    // -----------------------------------------------------------------
    //  Delimiter-line tightening: real delimiter lines only
    // -----------------------------------------------------------------

    public function testOpeningDelimiterWithTrailingWhitespaceAccepted(): void
    {
        // "---  " (spaces after opening delimiter) is a real delimiter line.
        $raw = "---  \nname: ws-open\ndescription: Trailing whitespace on opening line\ntools:\n  - read\n---\nbody\n";

        $dto = $this->rawParse($raw);
        $this->assertSame('ws-open', $dto->name);
        $this->assertSame('body', $dto->instructions);
    }

    public function testClosingDelimiterWithTrailingJunkRejected(): void
    {
        // "--- extra junk" on a closing line is NOT a real delimiter.
        $raw = "---\nname: bad-close\ndescription: Closing line has junk\ntools:\n  - read\n--- extra junk\nbody\n";

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/no closing delimiter/');

        $this->parser->parseContent($raw, '/test/bad-close.md');
    }

    public function testClosingDelimiterWithTrailingWhitespaceAccepted(): void
    {
        // "---  " (spaces after closing delimiter) is a real delimiter line.
        $raw = "---\nname: ws-close\ndescription: Trailing whitespace on closing line\ntools:\n  - read\n---  \nbody\n";

        $dto = $this->rawParse($raw);
        $this->assertSame('ws-close', $dto->name);
        $this->assertSame('body', $dto->instructions);
    }

    public function testDotsClosingDelimiterWithTrailingJunkRejected(): void
    {
        // "... extra junk" is not a real delimiter.
        $raw = "---\nname: bad-dot-close\ndescription: Dots closing with junk\ntools:\n  - read\n... extra junk\nbody\n";

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/no closing delimiter/');

        $this->parser->parseContent($raw, '/test/bad-dot-close.md');
    }

    // -----------------------------------------------------------------
    //  List-shape enforcement: mapping/associative arrays rejected
    // -----------------------------------------------------------------

    public function testToolsAssociativeMapRejected(): void
    {
        // tools: { read: read } is an associative map, not a list.
        $content = $this->wrapContent([
            'name' => 'tools-map',
            'description' => 'Associative tools',
            'tools' => ['read' => 'read'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"tools".*list.*associative/');

        $this->parser->parseContent($content, '/test/tools-map.md');
    }

    public function testSkillsAssociativeMapRejected(): void
    {
        // skills: { foo: bar } is an associative map, not a list.
        $content = $this->wrapContent([
            'name' => 'skills-map',
            'description' => 'Associative skills',
            'tools' => ['read'],
            'skills' => ['testing' => 'testing'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"skills".*list.*associative/');

        $this->parser->parseContent($content, '/test/skills-map.md');
    }

    public function testTopLevelMcpAssociativeMapIsUnknownField(): void
    {
        // Top-level mcp frontmatter is rejected entirely (use tools mcp: selectors).
        $raw = "---\nname: mcp-tools-map\ndescription: MCP tools map\ntools:\n  - read\nmcp:\n  mode: specific\n  tools:\n    read: read\n---\n";

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field "mcp"/');

        $this->parser->parseContent($raw, '/test/mcp-tools-map.md');
    }

    // -----------------------------------------------------------------
    //  Valid definitions
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function testExtensionsListIsParsedAndOmittedMeansNull(): void
    {
        $with = $this->validFrontmatter();
        $with['extensions'] = [
            'Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension',
            'Ineersa\\HatfieldExt\\CastorLlmMode\\CastorLlmModeExtension',
        ];
        $dto = $this->rawParse($this->wrapContent($with, "body\n"));
        $this->assertSame([
            'Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension',
            'Ineersa\\HatfieldExt\\CastorLlmMode\\CastorLlmModeExtension',
        ], $dto->extensions);

        $minimal = $this->rawParse($this->wrapContent([
            'name' => 'bare',
            'description' => 'Bare agent',
        ], "body\n"));
        $this->assertNull($minimal->extensions);
    }

    public function testExtensionsRejectsBlankAndNonStringEntries(): void
    {
        $fm = $this->validFrontmatter();
        $fm['extensions'] = ['Valid\\Class', ''];

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/extensions/');
        $this->rawParse($this->wrapContent($fm, "body\n"));
    }

    public function testExtensionsRejectsAssociativeMap(): void
    {
        $fm = $this->validFrontmatter();
        $fm['extensions'] = ['a' => 'Valid\\Class'];

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/extensions/');
        $this->rawParse($this->wrapContent($fm, "body\n"));
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
                                $lines[] = '  - '.json_encode($item, \JSON_UNESCAPED_SLASHES);
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
                            $lines[] = "  {$k}: ".json_encode($v, \JSON_UNESCAPED_SLASHES);
                        } elseif (\is_bool($v)) {
                            $lines[] = "  {$k}: ".($v ? 'true' : 'false');
                        } elseif (\is_array($v)) {
                            $lines[] = "  {$k}:";
                            foreach ($v as $item) {
                                $lines[] = '    - '.json_encode($item, \JSON_UNESCAPED_SLASHES);
                            }
                        } elseif (\is_int($v)) {
                            $lines[] = "  {$k}: {$v}";
                        } else {
                            $lines[] = "  {$k}: ".json_encode($v, \JSON_UNESCAPED_SLASHES);
                        }
                    }
                }
            } elseif (\is_string($value)) {
                $lines[] = "{$key}: ".json_encode($value, \JSON_UNESCAPED_SLASHES);
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

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function parse(array $frontmatter, string $path = '/test/agent.md'): AgentDefinitionDTO
    {
        return $this->parser->parseContent($this->wrapContent($frontmatter), $path);
    }

    private function rawParse(string $raw, string $path = '/test/agent.md'): AgentDefinitionDTO
    {
        return $this->parser->parseContent($raw, $path);
    }

    private function validFrontmatter(): array
    {
        return [
            'name' => 'my-scout',
            'description' => 'A custom scout agent',
            'tools' => ['read', 'ide_find_file', 'semantic-search'],
            'model' => 'deepseek/deepseek-v4-flash',
            'thinking' => 'low',
            'skills' => ['testing'],
            'inheritProjectContext' => false,
            'systemPromptMode' => 'append',
            'parallelAllowed' => true,
        ];
    }
}
