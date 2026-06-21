<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate\Tests;

use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateFrontmatterParser;
use PHPUnit\Framework\TestCase;

final class PromptTemplateFrontmatterParserTest extends TestCase
{
    private PromptTemplateFrontmatterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor());
    }

    private function parse(string $raw, string $filePath = '/test/template.md'): array
    {
        return $this->parser->parse($raw, $filePath);
    }

    public function testValidFrontmatterAndTrimmedBody(): void
    {
        $raw = "---\ndescription: Review code\n---\n\nReview the changes carefully.\n";
        $result = $this->parse($raw);

        self::assertSame('Review the changes carefully.', $result['body']);
        self::assertSame('Review code', $result['description']);
        self::assertEmpty($result['diagnostics']);
    }

    public function testNoFrontmatter(): void
    {
        $raw = "Just a plain prompt with no frontmatter.\nSecond line.\n";
        $result = $this->parse($raw);

        // Non-frontmatter bodies preserve original whitespace (only CRLF normalization).
        self::assertSame("Just a plain prompt with no frontmatter.\nSecond line.\n", $result['body']);
        self::assertSame('', $result['description']);
        self::assertEmpty($result['diagnostics']);
    }

    public function testStartsWithDashButNoClosingDelimiter(): void
    {
        $raw = "---\nsome text\nbut no closing ---\n";
        $result = $this->parse($raw);

        // No closing delimiter → treated as body, no frontmatter. Whitespace preserved.
        self::assertSame("---\nsome text\nbut no closing ---\n", $result['body']);
        self::assertSame('', $result['description']);
        self::assertEmpty($result['diagnostics']);
    }

    public function testEmptyFrontmatter(): void
    {
        $raw = "---\n---\nBody after empty frontmatter.\n";
        $result = $this->parse($raw);

        self::assertSame('Body after empty frontmatter.', $result['body']);
        self::assertSame('', $result['description']);
        self::assertEmpty($result['diagnostics']);
    }

    public function testCrlfNormalization(): void
    {
        $raw = "---\r\ndescription: Review\r\n---\r\n\r\nBody line 1\r\nBody line 2\r\n";
        $result = $this->parse($raw);

        self::assertSame("Body line 1\nBody line 2", $result['body']);
        self::assertSame('Review', $result['description']);
        self::assertEmpty($result['diagnostics']);
    }

    public function testCrNormalization(): void
    {
        $raw = "---\rdescription: Review\r---\r\rBody\r";
        $result = $this->parse($raw);

        self::assertSame('Body', $result['body']);
        self::assertSame('Review', $result['description']);
        self::assertEmpty($result['diagnostics']);
    }

    public function testUnknownFrontmatterKeysIgnored(): void
    {
        $raw = "---\ndescription: My template\nargument-hint: <branch> <message>\nunknown_key: some value\n---\n\nTemplate body.\n";
        $result = $this->parse($raw);

        self::assertSame('Template body.', $result['body']);
        self::assertSame('My template', $result['description']);
        self::assertEmpty($result['diagnostics']);
    }

    public function testInvalidYamlReturnsDiagnostic(): void
    {
        // Invalid YAML (unclosed quoted value).
        $raw = "---\ndescription: \"unclosed\n---\n\nBody after bad frontmatter.\n";
        $result = $this->parse($raw, '/test/bad-template.md');

        self::assertSame('Body after bad frontmatter.', $result['body']);
        self::assertSame('', $result['description']);
        self::assertCount(1, $result['diagnostics']);
        self::assertSame('yaml_error', $result['diagnostics'][0]->type);
        self::assertSame('/test/bad-template.md', $result['diagnostics'][0]->path);
    }

    public function testDescriptionEmptyStringIgnored(): void
    {
        $raw = "---\ndescription: ''\n---\n\nFirst line of body.\n";
        $result = $this->parse($raw);

        self::assertSame('First line of body.', $result['body']);
        self::assertSame('', $result['description']);
    }

    public function testDescriptionWhitespaceOnlyIgnored(): void
    {
        $raw = "---\ndescription: '   '\n---\n\nFirst line of body.\n";
        $result = $this->parse($raw);

        self::assertSame('First line of body.', $result['body']);
        self::assertSame('', $result['description']);
    }

    public function testYamlFrontmatterWithMultipleKeys(): void
    {
        $raw = "---\ndescription: Git review\npriority: high\nowner: team\n---\n\nReview staged changes.\n";
        $result = $this->parse($raw);

        self::assertSame('Review staged changes.', $result['body']);
        self::assertSame('Git review', $result['description']);
        // Unknown keys ignored, no diagnostics for valid YAML.
        self::assertEmpty($result['diagnostics']);
    }
}
