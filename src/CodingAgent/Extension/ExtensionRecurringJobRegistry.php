<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/** Extension definitions remain extension-owned; Symfony owns occurrence calculation. */
final class ExtensionRecurringJobRegistry
{
    /** @var array<string, array{source: RecurringJobSourceInterface, owner: ?string}> */
    private array $sources = [];
    /** @var array<string, string> */
    private array $registered = [];

    public function __construct(
        #[AutowireServiceClosure('scheduler.provider.default')] private readonly \Closure $provider,
        private readonly ExtensionAgentJobRegistry $handlers,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')] private readonly string $runId,
    ) {
    }

    public function register(string $id, RecurringJobSourceInterface $source): void
    {
        if (isset($this->sources[$id]) || '' === trim($id)) {
            throw new \InvalidArgumentException('Recurring job source must have a unique nonempty id.');
        }
        if ([] === $this->sources && '' !== $this->runId) {
            $this->schedule()->add(RecurringMessage::every('1 second', new RefreshExtensionRecurringJobs()));
        }
        $this->sources[$id] = ['source' => $source, 'owner' => ExtensionRegistrationContext::currentOwnerClass()];
    }

    #[AsMessageHandler]
    public function refresh(RefreshExtensionRecurringJobs $message): void
    {
        $current = [];
        foreach ($this->sources as $id => $registration) {
            foreach ($registration['source']->jobs($this->runId) as $job) {
                if (null === $this->handlers->get($job->handlerId) || $this->handlers->ownerClass($job->handlerId) !== $registration['owner']) {
                    throw new \RuntimeException('Recurring job handler must belong to its registered source extension.');
                }
                $recurring = RecurringMessage::every($job->every, new ExtensionRecurringJobMessageProvider($id, $job, $this->runId), $job->from, $job->until ?? new \DateTimeImmutable('3000-01-01'));
                $current[$id.':'.$job->id] = $recurring->getId();
                if (!isset($this->registered[$id.':'.$job->id])) {
                    $this->schedule()->add($recurring);
                }
            }
        }
        foreach (array_diff_key($this->registered, $current) as $messageId) {
            $this->schedule()->removeById($messageId);
        }
        $this->registered = $current;
    }

    private function schedule(): Schedule
    {
        /** @var ScheduleProviderInterface $provider */
        $provider = ($this->provider)();

        return $provider->getSchedule();
    }
}
