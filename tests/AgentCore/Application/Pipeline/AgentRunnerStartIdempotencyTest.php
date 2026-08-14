<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\AgentRunner;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class AgentRunnerStartIdempotencyTest extends TestCase
{
    public function testExplicitRunIdUsesStableStartStepAndIdempotencyKeyOnRepeat(): void
    {
        $bus = new TestMessageBus();
        $runner = new AgentRunner($bus, new Serializer([new ObjectNormalizer(classMetadataFactory: ($cmf = new ClassMetadataFactory(new AttributeLoader())), nameConverter: new MetadataAwareNameConverter($cmf, new CamelCaseToSnakeCaseNameConverter()))]));

        $runId = '11111111-1111-4111-8111-111111111111';
        $input = new StartRunInput(
            systemPrompt: 'sys',
            messages: [],
            runId: $runId,
            metadata: new RunMetadata(model: 'test-model'),
        );

        $runner->start($input);
        $runner->start($input);

        $this->assertCount(2, $bus->messages);
        $this->assertInstanceOf(StartRun::class, $bus->messages[0]);
        $this->assertInstanceOf(StartRun::class, $bus->messages[1]);
        /** @var StartRun $first */
        $first = $bus->messages[0];
        /** @var StartRun $second */
        $second = $bus->messages[1];
        $this->assertSame($first->stepId(), $second->stepId());
        $this->assertSame($first->idempotencyKey(), $second->idempotencyKey());
        $this->assertStringStartsWith('start-', $first->stepId());
    }

    public function testGeneratedRunIdUsesDistinctHrtimeSteps(): void
    {
        $bus = new TestMessageBus();
        $runner = new AgentRunner($bus, new Serializer([new ObjectNormalizer(classMetadataFactory: ($cmf = new ClassMetadataFactory(new AttributeLoader())), nameConverter: new MetadataAwareNameConverter($cmf, new CamelCaseToSnakeCaseNameConverter()))]));

        $input = new StartRunInput(systemPrompt: 'sys', messages: [], metadata: new RunMetadata(model: 'test-model'));
        $runner->start($input);
        $runner->start($input);

        $this->assertCount(2, $bus->messages);
        /** @var StartRun $first */
        $first = $bus->messages[0];
        /** @var StartRun $second */
        $second = $bus->messages[1];
        $this->assertNotSame($first->stepId(), $second->stepId());
    }
}
