<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Definition;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionValidationException;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Definition\SystemPromptModeEnum;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validation;
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
        $reflectionExtractor = new ReflectionExtractor();

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());

        $objectNormalizer = new ObjectNormalizer(
            classMetadataFactory: $classMetadataFactory,
            nameConverter: null,
            propertyAccessor: PropertyAccess::createPropertyAccessor(),
            propertyTypeExtractor: $reflectionExtractor,
        );

        // A full Serializer is needed (not a bare ObjectNormalizer) so nested
        // DTOs (e.g. McpFrontmatterDTO inside AgentFrontmatterDTO) can be
        // denormalized recursively.
        $this->serializer = new Serializer(normalizers: [$objectNormalizer], encoders: []);

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $this->parser = new AgentDefinitionParser(
            frontmatterParser: new AgentFrontmatterParser(new MarkdownFrontmatterExtractor()),
            denormalizer: $this->serializer,
            validator: $this->validator,
        );
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
                                $lines[] = '    - '.\json_encode($item, JSON_UNESCAPED_SLASHES);
                            }
                        } elseif (\is_int($v)) {
                            $lines[] = "  {$k}: {$v}";
                        } else {
                            $lines[] = "  {$k}: ".\json_encode($v, JSON_UNESCAPED_SLASHES);
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

    // -----------------------------------------------------------------
    //  Valid definitions
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validFrontmatter(): array
    {
        return [
            'name' => 'my-scout',
            'description' => 'A custom scout agent',
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
        ];
    }

    public function testFullValidDefinitionPreservesBody(): void
    {
        $content = $this->wrapContent($this->validFrontmatter(), "You are a scout. Explore and report findings.\n");

        $dto = $this->rawParse($content);

        self::assertSame('my-scout', $dto->name);
        self::assertSame('A custom scout agent', $dto->description);
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
        $dto = $this->parse([
            'name' => 'minimal',
            'description' => 'Bare minimum',
            'tools' => ['read'],
        ], '/test/minimal.md');

        self::assertSame('minimal', $dto->name);
        self::assertSame('Bare minimum', $dto->description);
        self::assertSame(['read'], $dto->tools);
        self::assertNull($dto->model);
        self::assertNull($dto->thinking);
        self::assertSame([], $dto->skills);
        self::assertTrue($dto->inheritProjectContext);
        self::assertTrue($dto->inheritAgentsMd);
        self::assertSame(SystemPromptModeEnum::Replace, $dto->systemPromptMode);
        self::assertSame(1, $dto->maxDepth);
        self::assertTrue($dto->backgroundAllowed);
        self::assertTrue($dto->foregroundAllowed);
        self::assertTrue($dto->parallelAllowed);
        self::assertFalse($dto->disabled);
        self::assertNull($dto->handoffFormat);
        self::assertSame(McpAgentModeEnum::None, $dto->mcp->mode);
        self::assertSame([], $dto->mcp->tools);
    }

    public function testModesAllWithNoTools(): void
    {
        $dto = $this->parse([
            'name' => 'researcher',
            'description' => 'MCP all agent',
            'tools' => ['websearch__search'],
            'mcp' => ['mode' => 'all'],
        ]);

        self::assertSame(McpAgentModeEnum::All, $dto->mcp->mode);
        self::assertSame([], $dto->mcp->tools);
    }

    public function testThinkingOff(): void
    {
        $dto = $this->parse([
            'name' => 'no-think',
            'description' => 'Thinking off',
            'tools' => ['bash'],
            'thinking' => 'off',
        ]);

        self::assertSame('off', $dto->thinking);
    }

    public function testThinkingXhigh(): void
    {
        $dto = $this->parse([
            'name' => 'deep-think',
            'description' => 'Deep thinker',
            'tools' => ['read'],
            'thinking' => 'xhigh',
        ]);

        self::assertSame('xhigh', $dto->thinking);
    }

    public function testMaxDepthZero(): void
    {
        $dto = $this->parse([
            'name' => 'no-recursion',
            'description' => 'Cannot recurse',
            'tools' => ['read'],
            'maxDepth' => 0,
        ]);

        self::assertSame(0, $dto->maxDepth);
    }

    public function testMaxDepthFive(): void
    {
        $dto = $this->parse([
            'name' => 'deep-recursion',
            'description' => 'Deep recursion',
            'tools' => ['read'],
            'maxDepth' => 5,
        ]);

        self::assertSame(5, $dto->maxDepth);
    }

    public function testParallelAllowedTrueByDefault(): void
    {
        $dto = $this->parse([
            'name' => 'solo',
            'description' => 'Solo agent',
            'tools' => ['read'],
        ]);

        self::assertTrue($dto->parallelAllowed);
    }

    public function testParallelAllowedExplicitFalse(): void
    {
        $dto = $this->parse([
            'name' => 'solo',
            'description' => 'Solo agent',
            'tools' => ['read'],
            'parallelAllowed' => false,
        ]);

        self::assertFalse($dto->parallelAllowed);
    }

    public function testBodyWithMarkdownPreserved(): void
    {
        $content = $this->wrapContent([
            'name' => 'md-body',
            'description' => 'Markdown body test',
            'tools' => ['read'],
        ], "## Instructions\n\n- Step 1\n- Step 2\n\n```php\necho 'hello';\n```\n");

        $dto = $this->rawParse($content);

        self::assertStringContainsString('## Instructions', $dto->instructions);
        self::assertStringContainsString('- Step 1', $dto->instructions);
        self::assertStringContainsString("```php", $dto->instructions);
        self::assertStringContainsString("echo 'hello';", $dto->instructions);
    }

    public function testThinkingNullExplicit(): void
    {
        $dto = $this->parse([
            'name' => 'null-think',
            'description' => 'Explicit null thinking',
            'tools' => ['read'],
            'thinking' => null,
        ]);

        self::assertNull($dto->thinking);
    }

    public function testModelNullExplicit(): void
    {
        $dto = $this->parse([
            'name' => 'null-model',
            'description' => 'Explicit null model',
            'tools' => ['read'],
            'model' => null,
        ]);

        self::assertNull($dto->model);
    }

    public function testClosesWithDots(): void
    {
        $content = "---\nname: dots-closer\ndescription: Uses dots\ntools:\n  - read\n...\n\nBody after dots\n";

        $dto = $this->rawParse($content);
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

        self::assertNull($dto->tools);
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

        self::assertSame(['read', 'grep', 'find', 'ls', 'bash'], $dto->tools);
    }

    public function testToolsCommaSeparatedStringWithoutSpaces(): void
    {
        $dto = $this->parse([
            'name' => 'browser-like',
            'description' => 'Tight comma list',
            'tools' => 'read,bash,grep,find,ls',
        ]);

        self::assertSame(['read', 'bash', 'grep', 'find', 'ls'], $dto->tools);
    }

    public function testSkillSingularStringMergesIntoSkills(): void
    {
        $dto = $this->parse([
            'name' => 'architect-like',
            'description' => 'Singular skill alias',
            'tools' => ['read'],
            'skill' => 'improve-codebase-architecture',
        ]);

        self::assertSame(['improve-codebase-architecture'], $dto->skills);
    }

    public function testSkillsStringIsNormalized(): void
    {
        $dto = $this->parse([
            'name' => 'browser-like',
            'description' => 'String skills',
            'tools' => ['read'],
            'skills' => 'playwright-cli',
        ]);

        self::assertSame(['playwright-cli'], $dto->skills);
    }

    public function testRepresentativeUserAgentFrontmatterShapes(): void
    {
        $scout = $this->rawParse("---
name: scout
description: Fast codebase recon that returns compressed context for handoff
model: deepseek/deepseek-v4-flash
inheritProjectContext: true
---
Body
", '/home/user/.agents/scout.md');
        self::assertSame('scout', $scout->name);
        self::assertNull($scout->tools);

        $reviewer = $this->rawParse("---
name: reviewer
description: Senior code reviewer
tools: read, grep, find, ls, bash
---
Body
", '/home/user/.agents/reviewer.md');
        self::assertSame('reviewer', $reviewer->name);
        self::assertContains('read', $reviewer->tools);
        self::assertContains('bash', $reviewer->tools);
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
        $this->expectExceptionMessageMatches('/tools\[1\].*must be a string/');

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

    public function testInvalidMcpModeEnumThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-mcp',
            'description' => 'Bad MCP mode',
            'tools' => ['read'],
            'mcp' => ['mode' => 'sometimes'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/mcp\.mode.*must be one of.*none.*specific.*all/');

        $this->parser->parseContent($content, '/test/bad-mcp.md');
    }

    public function testMcpSpecificWithoutToolsThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'specific-no-tools',
            'description' => 'Specific mode without tools',
            'tools' => ['read'],
            'mcp' => ['mode' => 'specific'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/specific.*empty/');

        $this->parser->parseContent($content, '/test/specific-no-tools.md');
    }

    public function testMcpToolsWithNoneModeThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'none-with-tools',
            'description' => 'None mode with tools',
            'tools' => ['read'],
            'mcp' => ['mode' => 'none', 'tools' => ['context7__query-docs']],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/tools.*mode.*none/');

        $this->parser->parseContent($content, '/test/none-with-tools.md');
    }

    public function testMcpToolsWithAllModeThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'all-with-tools',
            'description' => 'All mode with tools',
            'tools' => ['read'],
            'mcp' => ['mode' => 'all', 'tools' => ['context7__query-docs']],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/tools.*mode.*all/');

        $this->parser->parseContent($content, '/test/all-with-tools.md');
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

    public function testDisabledRejectsInt(): void
    {
        // Serializer with strict type enforcement rejects int for bool.
        $content = $this->wrapContent([
            'name' => 'int-disabled',
            'description' => 'Int for bool',
            'tools' => ['read'],
            'disabled' => 1,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/disabled.*must be of type bool/');

        $this->parser->parseContent($content, '/test/int-disabled.md');
    }

    public function testBothLaunchModesFalseThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'unlaunchable',
            'description' => 'Cannot launch',
            'tools' => ['read'],
            'backgroundAllowed' => false,
            'foregroundAllowed' => false,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/cannot both be false/');

        $this->parser->parseContent($content, '/test/unlaunchable.md');
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

    public function testMaxDepthTooLowThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-depth',
            'description' => 'Depth too low',
            'tools' => ['read'],
            'maxDepth' => -1,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/maxDepth.*between 0 and 5/');

        $this->parser->parseContent($content, '/test/bad-depth.md');
    }

    public function testMaxDepthTooHighThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'bad-depth-high',
            'description' => 'Depth too high',
            'tools' => ['read'],
            'maxDepth' => 6,
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/maxDepth.*between 0 and 5/');

        $this->parser->parseContent($content, '/test/bad-depth-high.md');
    }

    public function testMaxDepthRejectsString(): void
    {
        // Serializer with strict type enforcement rejects quoted strings for int,
        // even numeric strings like "3" that PHP would otherwise coerce.
        $content = $this->wrapContent([
            'name' => 'string-depth',
            'description' => 'String depth',
            'tools' => ['read'],
            'maxDepth' => '3',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/maxDepth.*must be of type int/');

        $this->parser->parseContent($content, '/test/string-depth.md');
    }

    public function testMcpFieldRejectsStringInsteadOfObject(): void
    {
        $content = $this->wrapContent([
            'name' => 'string-mcp',
            'description' => 'String instead of MCP object',
            'tools' => ['read'],
            'mcp' => 'none',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/mcp/');

        $this->parser->parseContent($content, '/test/string-mcp.md');
    }

    public function testSkillsStringScalarIsNormalized(): void
    {
        $dto = $this->parse([
            'name' => 'string-skills',
            'description' => 'String skills',
            'tools' => ['read'],
            'skills' => 'testing',
        ]);

        self::assertSame(['testing'], $dto->skills);
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

    public function testUnknownMcpSubFieldThrows(): void
    {
        $content = $this->wrapContent([
            'name' => 'unknown-mcp-field',
            'description' => 'Unknown MCP sub field',
            'tools' => ['read'],
            'mcp' => ['mode' => 'none', 'extraStuff' => true],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field.*extraStuff/');

        $this->parser->parseContent($content, '/test/unknown-mcp.md');
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

        self::assertSame('my-custom-agent-2', $dto->name);
    }

    // -----------------------------------------------------------------
    //  Reviewer fixes: closing delimiter, BOM, explicit nulls, whitespace
    // -----------------------------------------------------------------

    public function testBomStrippedBeforeFrontmatterCheck(): void
    {
        $raw = "\xEF\xBB\xBF---\nname: bom-stripped\ndescription: BOM test\ntools:\n  - read\n---\nbody\n";

        $dto = $this->rawParse($raw);
        self::assertSame('bom-stripped', $dto->name);
        self::assertSame('body', $dto->instructions);
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

    public function testMcpExplicitNullTreatedAsDefault(): void
    {
        $dto = $this->parse([
            'name' => 'null-mcp',
            'description' => 'Explicit null MCP',
            'tools' => ['read'],
            'mcp' => null,
        ]);

        self::assertSame(McpAgentModeEnum::None, $dto->mcp->mode);
        self::assertSame([], $dto->mcp->tools);
    }

    public function testMcpToolsWithoutModeRejected(): void
    {
        $content = $this->wrapContent([
            'name' => 'tools-no-mode',
            'description' => 'MCP tools without mode',
            'tools' => ['read'],
            'mcp' => ['tools' => ['context7__query-docs']],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/tools.*mode.*none/');

        $this->parser->parseContent($content, '/test/tools-no-mode.md');
    }

    public function testParseFileWithRealFilePopulatesPathAndDirectory(): void
    {
        $tmpDir = TestDirectoryIsolation::createProjectTempDir();
        try {
            $filePath = $tmpDir.'/test-agent.md';
            $raw = "---\nname: real-file\ndescription: Real file test\ntools:\n  - read\n---\nbody\n";
            file_put_contents($filePath, $raw);

            $dto = $this->parser->parseFile($filePath);
            self::assertSame('real-file', $dto->name);
            self::assertSame($filePath, $dto->sourcePath);
            self::assertSame($tmpDir, $dto->sourceDirectory);
            self::assertSame('body', $dto->instructions);
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

    public function testMcpToolsEntryWhitespaceOnlyRejected(): void
    {
        // Whitespace-only entries trigger the leading/trailing whitespace Regex
        // (the NotBlank without normalizer:trim lets non-falsy whitespace through).
        $content = $this->wrapContent([
            'name' => 'mcp-ws-only',
            'description' => 'MCP whitespace-only tool',
            'tools' => ['read'],
            'mcp' => ['mode' => 'specific', 'tools' => ['   ']],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/mcp\.tools\[0\].*must not have leading or trailing whitespace/');

        $this->parser->parseContent($content, '/test/mcp-ws-only.md');
    }

    public function testMcpToolsEntryWithSurroundingWhitespaceRejected(): void
    {
        // Surrounding whitespace in mcp.tools entries must be rejected.
        $content = $this->wrapContent([
            'name' => 'mcp-ws-surround',
            'description' => 'MCP tool with surrounding whitespace',
            'tools' => ['read'],
            'mcp' => ['mode' => 'specific', 'tools' => ['  context7__query-docs  ']],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/mcp\.tools\[0\].*must not have leading or trailing whitespace/');

        $this->parser->parseContent($content, '/test/mcp-ws-surround.md');
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

    public function testMcpModeNullTreatedAsNone(): void
    {
        $dto = $this->parse([
            'name' => 'mcp-mode-null',
            'description' => 'MCP mode explicit null',
            'tools' => ['read'],
            'mcp' => ['mode' => null],
        ]);

        self::assertSame(McpAgentModeEnum::None, $dto->mcp->mode);
        self::assertSame([], $dto->mcp->tools);
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

    public function testSerializerRejectsUnknownMcpSubField(): void
    {
        $content = $this->wrapContent([
            'name' => 'guard-mcp',
            'description' => 'Guard MCP',
            'tools' => ['read'],
            'mcp' => ['mode' => 'specific', 'tools' => ['context7__query-docs'], 'weirdField' => 'nope'],
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/unknown field.*weirdField/');

        $this->parser->parseContent($content, '/test/extra-mcp.md');
    }

    public function testNameLeadingWhitespaceTrimmed(): void
    {
        $dto = $this->parse([
            'name' => '  trimmed-name  ',
            'description' => 'Name with surrounding whitespace',
            'tools' => ['read'],
        ]);

        self::assertSame('trimmed-name', $dto->name);
    }

    public function testDescriptionLeadingWhitespaceTrimmed(): void
    {
        $dto = $this->parse([
            'name' => 'desc-trim',
            'description' => '  trimmed description  ',
            'tools' => ['read'],
        ]);

        self::assertSame('trimmed description', $dto->description);
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

    public function testBackgroundAllowedRejectsQuotedTrueString(): void
    {
        // "true" in YAML quotes is a string, not the YAML boolean true.
        // Strict type enforcement must reject string for bool.
        $content = $this->wrapContent([
            'name' => 'coerce-bg',
            'description' => 'String true for bool',
            'tools' => ['read'],
            'backgroundAllowed' => 'true',
        ]);

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/backgroundAllowed.*must be of type bool/');

        $this->parser->parseContent($content, '/test/coerce-bg-true.md');
    }

    // -----------------------------------------------------------------
    //  Delimiter-line tightening: real delimiter lines only
    // -----------------------------------------------------------------

    public function testOpeningDelimiterWithTrailingWhitespaceAccepted(): void
    {
        // "---  " (spaces after opening delimiter) is a real delimiter line.
        $raw = "---  \nname: ws-open\ndescription: Trailing whitespace on opening line\ntools:\n  - read\n---\nbody\n";

        $dto = $this->rawParse($raw);
        self::assertSame('ws-open', $dto->name);
        self::assertSame('body', $dto->instructions);
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
        self::assertSame('ws-close', $dto->name);
        self::assertSame('body', $dto->instructions);
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

    public function testMcpToolsAssociativeMapRejected(): void
    {
        // mcp.tools: { read: read } is an associative map, not a list.
        $raw = "---\nname: mcp-tools-map\ndescription: MCP tools map\ntools:\n  - read\nmcp:\n  mode: specific\n  tools:\n    read: read\n---\n";

        $this->expectException(AgentDefinitionValidationException::class);
        $this->expectExceptionMessageMatches('/"mcp\.tools".*list.*associative/');

        $this->parser->parseContent($raw, '/test/mcp-tools-map.md');
    }
}
