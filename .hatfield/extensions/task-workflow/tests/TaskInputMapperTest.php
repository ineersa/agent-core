<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Exception\TaskInputException;
use Ineersa\HatfieldExt\TaskWorkflow\Validation\TaskInputMapper;
use PHPUnit\Framework\TestCase;

final class TaskInputMapperTest extends TestCase
{
    public function testMapsSupportedInputsWithoutRewritingMarkdown(): void
    {
        $body = "# Free Markdown\n\n$(not shell)\n";
        $create = TaskInputMapper::map(['title' => 'Task', 'body' => $body], 'create');
        $this->assertSame($body, $create->body);
        $move = TaskInputMapper::map(['task' => 'https://github.com/acme/project/issues/7', 'to' => 'DONE', 'pr' => '9', 'acceptedHead' => 'abc', 'adopt' => true], 'move');
        $this->assertSame('9', $move->pr);
        $this->assertTrue($move->adopt);
        $this->assertSame('DONE', $move->to);
        $list = TaskInputMapper::map(['page' => 2, 'perPage' => 5], 'list');
        $this->assertSame(2, $list->page);
        $this->assertSame(5, $list->perPage);
    }

    public function testRejectsLegacyAndRuntimeArgumentsWithTypedPaths(): void
    {
        foreach (['file', 'run_id', 'timeout', 'validationCommand', 'deleteBranch', 'requireCleanMain'] as $key) {
            try {
                TaskInputMapper::map(['task' => '7', 'to' => 'DONE', $key => true], 'move');
                $this->fail('Expected unsupported argument refusal');
            } catch (TaskInputException $exception) {
                $this->assertSame($key, $exception->errors[0]['path']);
            }
        }
    }

    public function testRejectsMissingRequiredValuesAndInvalidPaginationOrStatus(): void
    {
        foreach ([['create', ['title' => '', 'body' => '']], ['move', ['task' => '', 'to' => 'bogus']], ['list', ['page' => 0]], ['list', ['perPage' => 101]], ['read', ['task' => []]]] as [$operation, $arguments]) {
            try {
                TaskInputMapper::map($arguments, $operation);
                $this->fail('Expected typed validation refusal');
            } catch (TaskInputException $exception) {
                $this->assertNotEmpty($exception->errors);
                $this->assertArrayHasKey('path', $exception->errors[0]);
                $this->assertNotSame('', $exception->errors[0]['message']);
            }
        }
    }
}
