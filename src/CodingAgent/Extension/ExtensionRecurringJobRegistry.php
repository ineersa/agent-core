<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobSourceInterface;
use Ineersa\Hatfield\ExtensionApi\Session\DTO\ScheduleDTO;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/** Extension definitions remain extension-owned; first activation is explicit and Symfony owns repeat cadence. */
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
        private readonly MessageBusInterface $bus,
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
        $now = $this->clock->now();
        $current = [];
        foreach ($this->sources as $id => $registration) {
            foreach ($registration['source']->jobs($this->runId) as $job) {
                if (null === $this->handlers->get($job->handlerId) || $this->handlers->ownerClass($job->handlerId) !== $registration['owner']) {
                    throw new \RuntimeException('Recurring job handler must belong to its registered source extension.');
                }
                // Revisit future jobs on the existing refresh. Never queue an initial
                // execution before eligibility, so pending jobs can be replaced or removed.
                if ($now < $job->from || (null !== $job->until && $now >= $job->until)) {
                    continue;
                }
                $provider = new ExtensionRecurringJobMessageProvider($id, $job, $this->runId);
                $recurring = RecurringMessage::every($job->every, $provider, $job->from, $job->until ?? new \DateTimeImmutable('3000-01-01'));
                $key = $id.':'.$job->id;
                $messageId = $recurring->getId();
                $current[$key] = $messageId;
                $installed = $this->registered[$key] ?? null;
                if ($installed === $messageId) {
                    continue;
                }
                if (null !== $installed) {
                    $this->schedule()->removeById($installed);
                    unset($this->registered[$key]);
                }

                // Symfony starts new recurring triggers strictly after first observation.
                // Send the first eligible execution now and leave the original cadence intact.
                $context = new MessageContext('default', $messageId, $recurring->getTrigger(), $now);
                foreach ($provider->getMessages($context) as $initial) {
                    $this->bus->dispatch($initial->envelope, [new TransportNamesStamp((array) $initial->transportNames)]);
                }
                $this->schedule()->add($recurring);
                $this->registered[$key] = $messageId;
            }
        }
        foreach (array_diff_key($this->registered, $current) as $messageId) {
            $this->schedule()->removeById($messageId);
        }
        $this->registered = $current;
    }

    /** @return list<ScheduleDTO>|null */
    public function sessionSchedules(string $runId): ?array
    {
        $source = $this->sources['scheduling']['source'] ?? null;
        if (null === $source) {
            return null;
        }

        $now = $this->clock->now();
        $schedules = [];
        foreach ($source->jobs($runId) as $job) {
            if (null !== $job->until && $job->until <= $now) {
                continue;
            }
            // Only carry an interval-shaped label, never arbitrary source text.
            $label = 1 === preg_match('/\A[0-9]+ (?:seconds?|minutes?|hours?|days?|weeks?|months?|years?)\z/i', $job->every)
                ? 'Every '.substr($job->every, 0, 58) : 'Recurring schedule';
            $recurring = RecurringMessage::every($job->every, new \stdClass(), $job->from, $job->until ?? new \DateTimeImmutable('3000-01-01'));
            $schedules[] = new ScheduleDTO($job->id, $label, true, $recurring->getTrigger()->getNextRunDate($now));
        }

        return $schedules;
    }

    private function schedule(): Schedule
    {
        /** @var ScheduleProviderInterface $provider */
        $provider = ($this->provider)();

        return $provider->getSchedule();
    }
}
