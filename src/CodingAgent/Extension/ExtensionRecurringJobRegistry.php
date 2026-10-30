<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobSourceInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\CallbackTrigger;

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
        private readonly ClockInterface $clock = new Clock(),
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
                $periodic = $recurring->getTrigger();
                // The generator checkpoints the refresh after this handler returns.
                // Keep initial eligibility strictly beyond it, without shifting the original cadence.
                $initial = max($job->from, $this->clock->now()->modify('+1 microsecond'));
                $trigger = new CallbackTrigger(
                    static fn (\DateTimeImmutable $after): ?\DateTimeImmutable => $after < $initial && (null === $job->until || $initial < $job->until)
                            ? $initial : $periodic->getNextRunDate($after),
                    (string) $periodic,
                );
                $recurring = RecurringMessage::trigger($trigger, $recurring->getProvider());
                $key = $id.':'.$job->id;
                $current[$key] = $recurring->getId();
                $installed = $this->registered[$key] ?? null;
                if ($installed !== $recurring->getId()) {
                    if (null !== $installed) {
                        $this->schedule()->removeById($installed);
                    }
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
