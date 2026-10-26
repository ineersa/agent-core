<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\Support;

use Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/** Test-only completed-refresh barrier over controller JSONL. */
final class OptionalConsumerProbeExtension implements HatfieldExtensionInterface, EventSubscriberInterface
{
    public function register(ExtensionApiInterface $api): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageHandledEvent::class => 'onHandled'];
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        if ($event->getEnvelope()->getMessage() instanceof McpRefreshCatalogCommand) {
            $this->emit('test.mcp.refreshed', []);
        }
    }

    /** @param array<string, mixed> $payload */
    private function emit(string $type, array $payload): void
    {
        fwrite(\STDOUT, json_encode(['v' => 1, 'type' => $type, 'runId' => '', 'seq' => 0, 'payload' => $payload], \JSON_THROW_ON_ERROR)."\n");
    }
}
