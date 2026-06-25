<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskStatusEnumTest extends TestCase
{
    #[Test]
    public function normalizesAcceptedForms(): void
    {
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::fromMixed('IN_PROGRESS'));
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::fromMixed('in-progress'));
        $this->assertSame(TaskStatusEnum::CODE_REVIEW, TaskStatusEnum::fromMixed('CODE_REVIEW'));
    }

    #[Test]
    public function unknownThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown task status:');
        TaskStatusEnum::fromMixed('nope');
    }
}
