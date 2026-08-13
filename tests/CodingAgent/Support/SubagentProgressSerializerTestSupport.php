<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Runtime\SubagentLiveCatalog;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Thin test accessors over AttributeSerializerValidatorTestFactory for subagent_progress.
 */
final class SubagentProgressSerializerTestSupport
{
    /**
     * @return array{0: SerializerInterface&NormalizerInterface&DenormalizerInterface, 1: ValidatorInterface}
     */
    public static function stack(): array
    {
        return AttributeSerializerValidatorTestFactory::create();
    }

    public static function denormalizer(): DenormalizerInterface
    {
        return self::stack()[0];
    }

    public static function normalizer(): NormalizerInterface
    {
        return self::stack()[0];
    }

    public static function validator(): ValidatorInterface
    {
        return self::stack()[1];
    }

    public static function ingestCatalogEvent(SubagentLiveCatalog $catalog, RuntimeEvent $event): void
    {
        if (!str_contains($event->type, 'tool_execution')) {
            return;
        }
        $progress = $event->payload['subagent_progress'] ?? null;
        if (!\is_array($progress)) {
            return;
        }
        try {
            $snapshot = self::denormalizer()->denormalize($progress, SubagentProgressSnapshotInterface::class);
            if (!$snapshot instanceof SubagentProgressSnapshotInterface) {
                return;
            }
            $violations = self::validator()->validate($snapshot);
            if ($violations->count() > 0) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        $catalog->ingestSnapshot($snapshot);
    }
}
