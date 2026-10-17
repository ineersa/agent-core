<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryEligibilityPolicy;
use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryVerifier;
use Ineersa\AgentCore\Application\Tool\ToolCompletionMailboxConsumer;
use Ineersa\AgentCore\Application\Tool\ToolCompletionNotificationRenderer;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionEvidenceReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionDeliveryFenceProviderInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO;

/** Legacy command fixtures have no retained completions; real delivery tests use the kernel. */
final class ToolCompletionMailboxTestFactory
{
    public static function policy(CommandStoreInterface $commandStore, CommandRouter $commandRouter): CommandMailboxPolicy
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer(withBackedEnumNormalizer: true);
        $fences = new class implements ToolCompletionDeliveryFenceProviderInterface {
            public function capture(string $runId): ToolCompletionDeliveryFenceDTO
            {
                return new ToolCompletionDeliveryFenceDTO(0, 0, 0);
            }
        };
        $reader = new class implements RetainedToolCompletionEvidenceReaderInterface {
            public function read(string $authorizedRootSessionId, string $sourceRunId, string $messageId, ?int $maxBodyBytes = null, ?int $acceptedSourceSeq = null): array
            {
                throw new \LogicException('This command-only fixture has no retained completion bodies.');
            }
        };
        $verifier = new ToolCompletionDeliveryVerifier(new InMemoryEventStore(), RetainedCompletionTestFactory::codec(), new IdentityOwningRootSessionResolver());
        $consumer = new ToolCompletionMailboxConsumer(new InMemoryToolCompletionRecipientRepository(), new ToolCompletionDeliveryEligibilityPolicy($fences), new ToolCompletionNotificationRenderer($verifier, $reader, $serializer), $serializer);

        return new CommandMailboxPolicy($commandStore, $commandRouter, $consumer);
    }
}
