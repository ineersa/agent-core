<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskMarkdownTest extends TestCase
{
    #[Test]
    public function renderTaskRoundTrip(): void
    {
        $text = TaskMarkdown::renderTask('My Task', 'body', ['a1']);
        $this->assertSame('My Task', TaskMarkdown::extractTitle($text, 'x.md'));
        $this->assertSame('TODO', TaskMarkdown::extractField($text, 'Status'));
        // Empty metadata fields must not spill into the next label value.
        $this->assertNull(TaskMarkdown::extractField($text, 'Worktree'));
        $this->assertNull(TaskMarkdown::extractField($text, 'Branch'));
        $this->assertNull(TaskMarkdown::extractField($text, 'Fork run'));
    }

    #[Test]
    public function updateFieldReplacesAndAppends(): void
    {
        $text = TaskMarkdown::renderTask('T');
        $updated = TaskMarkdown::updateField($text, 'Branch', 'task/foo');
        $this->assertStringContainsString('Branch: task/foo', $updated);
        $appended = TaskMarkdown::updateField($updated, 'Fork run', 'abc');
        $this->assertStringContainsString('Fork run: abc', $appended);
    }

    #[Test]
    public function appendLogAppendsSection(): void
    {
        $text = TaskMarkdown::renderTask('T');
        $out = TaskMarkdown::appendLog($text, ['line1']);
        $this->assertStringContainsString('## Task workflow update -', $out);
        $this->assertStringContainsString('- line1', $out);
    }

    #[Test]
    public function slugifyEdgeCases(): void
    {
        $this->assertSame('task', TaskMarkdown::slugify('!!!'));
        $this->assertLessThanOrEqual(80, \strlen(TaskMarkdown::slugify(str_repeat('a', 200))));
    }
}
