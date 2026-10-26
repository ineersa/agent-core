<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\Support;

use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Registers real job capability and enqueues one job before consumers launch. */
final class OptionalJobProbeExtension implements HatfieldExtensionInterface, EventSubscriberInterface, ExtensionAgentJobHandlerInterface
{
    private ExtensionApiInterface $api;

    public function register(ExtensionApiInterface $api): void
    {
        $this->api = $api;
        $api->registerExtensionAgentJobHandler('test.optional', $this);
    }

    public static function getSubscribedEvents(): array
    {
        return [ControllerSessionStartingEvent::class => 'onStarting'];
    }

    public function onStarting(ControllerSessionStartingEvent $event): void
    {
        $this->api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(handlerId: 'test.optional', payload: []));
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        fwrite(\STDOUT, json_encode(['v' => 1, 'type' => 'test.extension.completed', 'runId' => '', 'seq' => 0, 'payload' => []], \JSON_THROW_ON_ERROR)."\n");
    }
}
