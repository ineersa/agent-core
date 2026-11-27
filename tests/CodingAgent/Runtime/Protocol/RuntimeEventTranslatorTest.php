<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Protocol;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class RuntimeEventTranslatorTest extends TestCase
{
    public function testToolExecutionEndCarriesModelFacingTextPartsJoinedWithNewlines(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $codec = new ToolExecutionEndPayloadCodec($serializer);
        $translator = new RuntimeEventTranslator(new EventDispatcher(), $codec, $serializer);
        $result = new ToolCallResult('run', 1, 'step', 1, 'key', 'call-1', 0, [
            'content' => [
                ['type' => 'text', 'text' => 'first'],
                ['type' => 'thinking', 'text' => 'hidden'],
                ['type' => 'text', 'text' => ''],
                ['type' => 'text', 'text' => 'last'],
            ],
        ]);

        $event = $translator->translate(new RunEvent('run', 4, 1, RunEventTypeEnum::ToolExecutionEnd->value, $codec->toEventPayload($result)));

        $this->assertNotNull($event);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $event->type);
        $this->assertSame("first\nlast", $event->payload['result'] ?? null);
    }
}
