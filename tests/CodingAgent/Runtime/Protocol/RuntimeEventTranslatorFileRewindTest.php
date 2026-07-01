<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Protocol;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class RuntimeEventTranslatorFileRewindTest extends TestCase
{
    public function testFileRewindMetadataEventsAreDroppedFromRuntimeStream(): void
    {
        $translator = new RuntimeEventTranslator(new EventDispatcher());

        $checkpoint = new RunEvent('r1', 1, 1, RunEventTypeEnum::FileRewindCheckpointRecorded->value, ['kind' => 'user_boundary'], new \DateTimeImmutable());
        $restored = new RunEvent('r1', 2, 1, RunEventTypeEnum::FileRewindRestored->value, ['status' => 'succeeded'], new \DateTimeImmutable());

        self::assertNull($translator->translate($checkpoint));
        self::assertNull($translator->translate($restored));
    }
}
