<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\ConfigurableExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Session\DTO\BackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Session\DTO\ScheduleDTO;
use Ineersa\Hatfield\ExtensionApi\Session\Event\AssistantMessageCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\BackgroundJobEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\BackgroundJobStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputResolvedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionClosingEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionSelectedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionTitleChangedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ToolFinishedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ToolStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnCancelledEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnFailedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\SessionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function Amp\async;

final class Hatfield360Extension implements ConfigurableExtensionInterface, EventSubscriberInterface, TuiExtensionInterface, ExtensionCommandHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const int MAX_TERMINALS = 1024;
    private const int MAX_RESPONSE = 1000;

    // /reload replaces the extension instance, not the explicit launch.
    private static int $sequence = 0;
    private ?StatusClient $client = null;
    private bool $canBootstrap = false;
    private ?SessionContextInterface $session = null;
    private string $sessionId = '';
    private ?string $title = null;
    private int $epoch = 0;
    private bool $closing = false;
    /** @var array{id: string, status: string, trigger?: string, response?: string}|null */
    private ?array $turn = null;
    // Text of the latest main assistant message of the working turn.
    private string $response = '';
    private ?BackgroundSnapshotDTO $background = null;
    private ?\DateTimeImmutable $pollAt = null;
    /** @var list<array{id: string, label: string, recurring: bool, nextRunAt: ?int}>|null */
    private ?array $schedules = null;
    private ?\DateTimeImmutable $completionDue = null;
    private bool $completionReleased = false;
    /** @var list<array{request: string, id: string, reason: string}> */
    private array $waits = [];
    /** Overflow cannot be resolved by uncorrelated input; keep it until the response ends or the selection changes.
     * @var array{id: string, reason: string}|null */
    private ?array $waitOverflow = null;
    /** @var array<string, array<string, array{id: string, reason: string}>> */
    private array $childWaits = [];
    /** @var array<string, string> */
    private array $tools = [];
    /** @var array<string, int> */
    private array $childTerminals = [];
    /** @var Future<void>|null */
    private ?Future $drain = null;
    private bool $snapshotUnconfirmed = false;
    private ?\DateTimeImmutable $retryAt = null;
    private int $retryDelay = 1;
    private bool $snapshotOutage = false;
    private ?string $lastSnapshotFingerprint = null;
    /** @var list<array{string, string, int, int, array<string, mixed>}> */
    private array $pending = [];
    /** @var DeferredFuture<bool>|null */
    private ?DeferredFuture $notice = null;

    public function __construct(private readonly ClockInterface $clock = new MonotonicClock())
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SessionSelectedEvent::class => 'onSessionSelected',
            SessionClosingEvent::class => 'onSessionClosing',
            SessionTitleChangedEvent::class => 'onSessionTitleChanged',
            TurnStartedEvent::class => 'onTurnStarted',
            TurnCompletedEvent::class => 'onTurnCompleted',
            TurnFailedEvent::class => 'onTurnFailed',
            TurnCancelledEvent::class => 'onTurnCancelled',
            AssistantMessageCompletedEvent::class => 'onAssistantMessageCompleted',
            ToolStartedEvent::class => 'onToolStarted',
            ToolFinishedEvent::class => 'onToolFinished',
            InputRequestedEvent::class => 'onInputRequested',
            InputResolvedEvent::class => 'onInputResolved',
            ChildExecutionStartedEvent::class => 'onChildExecutionStarted',
            ChildExecutionEndedEvent::class => 'onChildExecutionEnded',
            BackgroundJobStartedEvent::class => 'onBackgroundJob',
            BackgroundJobEndedEvent::class => 'onBackgroundJob',
        ];
    }

    public function getConfiguration(): ConfigurationInterface
    {
        return new class implements ConfigurationInterface {
            public function getConfigTreeBuilder(): TreeBuilder
            {
                return new TreeBuilder('hatfield_360');
            }
        };
    }

    public function register(ExtensionApiInterface $api): void
    {
        $api->registerCommand(new CommandDefinitionDTO('h360-notify', description: 'Send an additional Hatfield 360 notification', usage: '/h360-notify <title> | <body>', acceptsArguments: true), $this);
        $this->session = $api->session();
        $this->client = StatusClient::fromEnvironment();
        $bootstrap = getenv('H360_STATUS_BOOTSTRAP_TOKEN');
        $this->canBootstrap = 'Linux' === \PHP_OS_FAMILY && false !== getenv('H360_STATUS_ENDPOINT')
            && false === getenv('H360_STATUS_TOKEN') && false === getenv('H360_STATUS_LAUNCH_ID')
            && \is_string($bootstrap) && 1 === preg_match('/\A[0-9a-f]{64}\z/', $bootstrap);
    }

    public function handle(string $args, CommandContextInterface $context): void
    {
        $parts = explode('|', $args, 2);
        $fields = ['title' => trim($parts[0]), 'body' => trim($parts[1] ?? '')];
        if (!EventSchema::acceptsNotification($fields)) {
            $context->notify('Usage: /h360-notify <title> | <body> (200 and 2,000 characters maximum).', 'warning');

            return;
        }
        if (!$this->observes($this->sessionId) || null !== $this->notice) {
            $context->notify('Hatfield 360 notification not sent: no selected session or another notice is pending.', 'warning');

            return;
        }
        $notice = $this->notice = new DeferredFuture();
        $this->enqueue('notification', ++self::$sequence, $fields);
        try {
            $accepted = $notice->getFuture()->await(new TimeoutCancellation(1));
        } catch (CancelledException) {
            // The desktop may have received an in-flight frame before the timeout.
            $accepted = false;
        }
        $context->notify($accepted ? 'Hatfield 360 accepted the notification.' : 'Hatfield 360 did not confirm the notification.', $accepted ? 'success' : 'warning');
    }

    public function registerTui(TuiExtensionContextInterface $context): void
    {
        // Inventory has no change event: sample it on the idle UI tick for
        // completion settling and delivery retries.
        $context->onTick($this->poll(...));
    }

    public function onSessionSelected(SessionSelectedEvent $event): void
    {
        if ($this->closing || (null === $this->client && !$this->canBootstrap)) {
            return;
        }

        $this->sessionId = $event->sessionId;
        $this->refreshTitle();
        $this->epoch = ++self::$sequence;
        $this->schedules = null;
        $this->background = null;
        $this->pollAt = null;
        // Selection does not establish an active response or settle a previous one.
        $this->turn = null;
        $this->response = '';
        $this->invalidateCompletion();
        $this->waits = [];
        $this->waitOverflow = null;
        $this->childWaits = [];
        $this->tools = [];
        $this->childTerminals = [];
        $this->snapshotUnconfirmed = false;
        $this->retryAt = null;
        $this->retryDelay = 1;
        $this->snapshotOutage = false;
        $this->lastSnapshotFingerprint = null;
        $this->snapshot(selection: true, refresh: true);
    }

    public function onSessionClosing(SessionClosingEvent $event): void
    {
        $sessionId = $event->sessionId;
        if ($this->closing || '' === $sessionId || $this->sessionId !== $sessionId) {
            return;
        }
        $this->closing = true;
        $this->invalidateCompletion();
        $this->discardPending();
        // Quit must not initiate manual authorization.
        if (null === $this->client) {
            return;
        }
        $this->enqueue('session.ended', ++self::$sequence, []);
        try {
            $this->drain?->await(new TimeoutCancellation(0.65));
        } catch (CancelledException) {
            $this->discardPending();
            $this->logDrop($sessionId);
        }
    }

    /** Only the title changes: turn and attention state, and so completion, stay as they are. */
    public function onSessionTitleChanged(SessionTitleChangedEvent $event): void
    {
        if (!$this->observes($event->sessionId)) {
            return;
        }
        $this->title = self::title($event->title);
        $this->snapshot(deduplicate: true);
    }

    public function onTurnStarted(TurnStartedEvent $event): void
    {
        // Internal model/tool steps stay in the same logical response.
        if (!$this->observes($event->sessionId) || 'working' === ($this->turn['status'] ?? null)) {
            return;
        }
        $this->refreshTitle();
        $this->turn = ['id' => (string) $event->sequence, 'status' => 'working'];
        if ('schedule' === $event->trigger) {
            $this->turn['trigger'] = 'schedule';
        }
        $this->response = '';
        $this->invalidateCompletion();
        $this->waits = [];
        $this->waitOverflow = null;
        $this->tools = [];
        $this->snapshot();
    }

    public function onTurnCompleted(TurnCompletedEvent $event): void
    {
        $this->finish($event->sessionId, 'completed');
    }

    public function onTurnFailed(TurnFailedEvent $event): void
    {
        $this->finish($event->sessionId, 'failed');
    }

    public function onTurnCancelled(TurnCancelledEvent $event): void
    {
        $this->finish($event->sessionId, 'cancelled');
    }

    public function onAssistantMessageCompleted(AssistantMessageCompletedEvent $event): void
    {
        // Each message replaces the previous one; a tool-only message leaves no response.
        if ($this->working($event->sessionId)) {
            $this->response = $event->text;
        }
    }

    public function onToolStarted(ToolStartedEvent $event): void
    {
        $key = hash('sha256', $event->toolCallId);
        $name = $event->toolName;
        if (!$this->working($event->sessionId) || null === $name || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 128
            || isset($this->tools[$key]) || \count($this->tools) >= 32) {
            return;
        }
        $this->tools[$key] = $name;
        $this->snapshot();
    }

    public function onToolFinished(ToolFinishedEvent $event): void
    {
        $key = hash('sha256', $event->toolCallId);
        if (!$this->working($event->sessionId) || !isset($this->tools[$key])) {
            return;
        }
        unset($this->tools[$key]);
        $this->snapshot();
    }

    public function onInputRequested(InputRequestedEvent $event): void
    {
        $reason = InputRequestTypeEnum::Approval === $event->type ? 'permission' : 'question';
        if (null !== $event->childRunId) {
            $this->child($event->sessionId, $event->sequence, $event->childRunId, $reason, $event->requestId);

            return;
        }
        if (!$this->working($event->sessionId)) {
            return;
        }
        $episode = ['id' => (string) (self::$sequence + 1), 'reason' => $reason];
        if (\count($this->waits) >= 2000 || null !== $this->waitOverflow) {
            $this->waitOverflow ??= $episode;
        } else {
            $this->waits[] = ['request' => hash('sha256', $event->requestId)] + $episode;
        }
        $this->snapshot();
    }

    public function onInputResolved(InputResolvedEvent $event): void
    {
        if (null !== $event->childRunId) {
            $this->child($event->sessionId, $event->sequence, $event->childRunId, 'resolved', $event->requestId);

            return;
        }
        if (!$this->observes($event->sessionId)) {
            return;
        }
        foreach ($this->waits as $index => $wait) {
            if ($wait['request'] === hash('sha256', $event->requestId)) {
                array_splice($this->waits, $index, 1);
                break;
            }
        }
        $this->snapshot();
    }

    public function onChildExecutionStarted(ChildExecutionStartedEvent $event): void
    {
        $this->child($event->sessionId, $event->sequence, $event->childRunId, 'started');
    }

    public function onChildExecutionEnded(ChildExecutionEndedEvent $event): void
    {
        $this->child($event->sessionId, $event->sequence, $event->childRunId, 'ended');
    }

    public function onBackgroundJob(BackgroundJobStartedEvent|BackgroundJobEndedEvent $event): void
    {
        if ($this->observes($event->sessionId)) {
            $this->snapshot(deduplicate: true, refresh: true);
        }
    }

    private function poll(): void
    {
        $now = $this->clock->now();
        if (!$this->observes($this->sessionId) || (null !== $this->pollAt && $now < $this->pollAt)) {
            return;
        }
        $this->pollAt = $now->modify('+500 milliseconds');
        $this->snapshot(deduplicate: true, polled: true);
    }

    private function finish(string $sessionId, string $outcome): void
    {
        if (!$this->working($sessionId)) {
            return;
        }
        $this->turn['status'] = $outcome;
        if ('completed' === $outcome && null !== ($response = self::response($this->response))) {
            $this->turn['response'] = $response;
        }
        $this->response = '';
        $this->waits = [];
        $this->waitOverflow = null;
        $this->tools = [];
        $this->snapshot(refresh: 'completed' === $outcome);
    }

    /** @param 'started'|'ended'|'question'|'permission'|'resolved' $kind */
    private function child(string $sessionId, int $sequence, string $id, string $kind, ?string $request = null): void
    {
        if (!$this->observes($sessionId)) {
            return;
        }
        if ('started' === $kind && $sequence > ($this->childTerminals[$id] ?? 0)) {
            $this->invalidateCompletion();
        }
        $attentionChanged = $this->observeChild($sequence, $id, $kind, $request);
        if ($attentionChanged && ('question' === $kind || 'permission' === $kind)) {
            $this->invalidateCompletion();
        }
        $refresh = 'started' === $kind || 'ended' === $kind;
        if ($attentionChanged || $refresh) {
            $this->snapshot(deduplicate: !$attentionChanged, refresh: $refresh);
        }
    }

    private function refreshTitle(): void
    {
        $this->title = null;
        if (null === $this->session || $this->session->getSessionId() !== $this->sessionId) {
            return;
        }
        try {
            $title = $this->session->getSessionDisplayTitle();
        } catch (\Throwable $exception) {
            $this->logger?->warning('hatfield_360.title_refresh_failed', [
                'component' => 'hatfield_360', 'event_type' => 'title_refresh_failed',
                'run_id' => $this->sessionId, 'session_id' => $this->sessionId, 'exception_class' => $exception::class,
            ]);

            return;
        }
        $this->title = self::title($title);
    }

    private static function title(?string $title): ?string
    {
        return null !== $title && mb_check_encoding($title, 'UTF-8') ? mb_substr($title, 0, 1024, 'UTF-8') : null;
    }

    /** Keeps the end of the final message, where the answer usually is. */
    private static function response(string $text): ?string
    {
        if (!mb_check_encoding($text, 'UTF-8') || '' === trim($text)) {
            return null;
        }

        return mb_strlen($text, 'UTF-8') <= self::MAX_RESPONSE ? $text : '…'.mb_substr($text, 1 - self::MAX_RESPONSE, null, 'UTF-8');
    }

    private function observeChild(int $sequence, string $id, string $kind, ?string $request): bool
    {
        if ($sequence <= ($this->childTerminals[$id] ?? 0)) {
            return false;
        }
        if ('ended' === $kind) {
            if (isset($this->childTerminals[$id]) || \count($this->childTerminals) < self::MAX_TERMINALS) {
                $this->childTerminals[$id] = $sequence;
            }
            $changed = isset($this->childWaits[$id]);
            unset($this->childWaits[$id]);

            return $changed;
        }
        if (null === $request) {
            return false;
        }
        if ('resolved' === $kind && isset($this->childWaits[$id][$request])) {
            unset($this->childWaits[$id][$request]);
            if ([] === $this->childWaits[$id]) {
                unset($this->childWaits[$id]);
            }

            return true;
        }
        if ('question' !== $kind && 'permission' !== $kind) {
            return false;
        }
        if (isset($this->childWaits[$id][$request]) || \count($this->childWaits[$id] ?? []) >= 16
            || (!isset($this->childWaits[$id]) && \count($this->childWaits) >= 1024)) {
            return false;
        }
        $this->childWaits[$id][$request] = ['id' => (string) (self::$sequence + 1), 'reason' => $kind];

        return true;
    }

    private function observes(string $sessionId): bool
    {
        return !$this->closing && '' !== $this->sessionId && $sessionId === $this->sessionId;
    }

    private function working(string $sessionId): bool
    {
        return $this->observes($sessionId) && 'working' === ($this->turn['status'] ?? null);
    }

    private function invalidateCompletion(): void
    {
        // A released frame might not have arrived; renewed work requires another quiet window.
        $this->completionDue = null;
        $this->completionReleased = false;
    }

    /**
     * Ordinary observations reuse the latest inventory sample. Selection,
     * child, job and completion changes read it again, as do polls.
     */
    private function snapshot(bool $selection = false, bool $deduplicate = false, bool $refresh = false, bool $polled = false): void
    {
        $epoch = $this->epoch;
        $sessionId = $this->sessionId;
        $session = $this->session;
        $due = $this->completionDue;
        $confirm = $polled && null !== $due && $this->clock->now() >= $due;
        if (null !== $session) {
            if ($session->getSessionId() !== $sessionId) {
                $this->invalidateCompletion();

                return;
            }
            if ($refresh || $polled || null === $this->background) {
                $this->background = $session->getBackgroundSnapshot();
            }
            if ($this->epoch !== $epoch || !$this->observes($sessionId) || ($confirm && $due !== $this->completionDue)) {
                return;
            }
            if ($session->getSessionId() !== $sessionId || $this->background->sessionId !== $sessionId) {
                $this->invalidateCompletion();

                return;
            }
        }
        $background = $this->background;
        $confirm = $confirm && 0 === $background?->activeSubagents;
        if (null !== $background) {
            $schedules = $background->schedules;
            $this->schedules = null === $schedules || \count($schedules) > 16 ? null : array_map(
                static fn (ScheduleDTO $schedule): array => [
                    'id' => $schedule->id,
                    'label' => mb_substr($schedule->label, 0, 64, 'UTF-8'),
                    'recurring' => $schedule->recurring,
                    'nextRunAt' => null === $schedule->nextRunAt ? null : (int) $schedule->nextRunAt->format('Uv'),
                ],
                $schedules,
            );
        }
        $children = $background?->activeSubagents;
        $scripts = $background?->backgroundScripts;
        // Prefer foreground input, then the oldest observed child episode.
        $first = $this->waits[0] ?? null;
        $attention = null === $first ? $this->waitOverflow : ['id' => $first['id'], 'reason' => $first['reason']];
        if (null === $attention) {
            foreach ($this->childWaits as $waits) {
                $candidate = $waits[array_key_first($waits) ?? ''] ?? null;
                if (null !== $candidate && (null === $attention || (int) $candidate['id'] < (int) $attention['id'])) {
                    $attention = $candidate;
                }
            }
        }
        $completed = 'completed' === ($this->turn['status'] ?? null);
        if (!$completed || 0 !== $children || null !== $attention) {
            $this->invalidateCompletion();
        } elseif (!$this->completionReleased) {
            if ($confirm) {
                $this->completionReleased = true;
                $this->completionDue = null;
            } else {
                $this->completionDue ??= $this->clock->now()->modify('+5 seconds');
            }
        }
        // Desktop completion is inferred from the snapshot itself, not a separate notice.
        if ($completed && 0 === $children && !$this->completionReleased) {
            $children = null;
        }
        $fields = [
            'provider' => 'hatfield', 'title' => $this->title, 'turn' => $this->turn, 'attention' => $attention,
            ...(null === $this->schedules || [] === $this->schedules ? [] : ['schedules' => $this->schedules]),
            'activeSubagents' => $children > 1024 ? null : $children,
            'backgroundScripts' => $scripts > 1024 ? null : $scripts, 'tool' => $this->tools[array_key_last($this->tools) ?? ''] ?? null,
        ];
        $fingerprint = hash('sha256', serialize($fields));
        $retry = $deduplicate && $fingerprint === $this->lastSnapshotFingerprint;
        if ($retry && (!$polled || !$this->snapshotUnconfirmed || null !== $this->drain || (null !== $this->retryAt && $this->clock->now() < $this->retryAt))) {
            return;
        }
        $this->lastSnapshotFingerprint = $fingerprint;
        $this->enqueue('session.snapshot', $selection && self::$sequence === $epoch ? $epoch : ++self::$sequence, $fields);
    }

    /** @param array<string, mixed> $fields */
    private function enqueue(string $kind, int $sequence, array $fields): void
    {
        // A notice is an ordering barrier: retain its preceding snapshot and
        // coalesce only adjacent unsent snapshots on either side of it.
        $last = array_key_last($this->pending);
        if ('session.snapshot' === $kind && null !== $last && 'session.snapshot' === $this->pending[$last][0]) {
            array_pop($this->pending);
        }
        $this->pending[] = [$kind, $this->sessionId, $sequence, $this->epoch, $fields];
        $this->drain ??= async(function (): void {
            try {
                while (null !== ($event = array_shift($this->pending))) {
                    [$kind, $sessionId, $sequence, $epoch, $fields] = $event;
                    $eligible = $this->epoch === $epoch && (!$this->closing || 'session.ended' === $kind);
                    $sent = false;
                    $failed = false;
                    if ($eligible) {
                        try {
                            $client = $this->client ??= ($this->closing ? null : HatfieldProcessIdentityProvider::register());
                            // Manual registration can yield while selection changes.
                            $eligible = $this->epoch === $epoch && (!$this->closing || 'session.ended' === $kind);
                            $sent = $eligible && null !== $client && $client->send($kind, $sessionId, $sequence, $epoch, $fields);
                        } catch (\Throwable) {
                            // Degrade only this item; a newer selection or notice may already be queued.
                            $failed = true;
                        }
                    }
                    if ('session.snapshot' === $kind && $this->epoch === $epoch && !$this->closing) {
                        $this->snapshotUnconfirmed = !$sent;
                        if ($sent) {
                            $this->retryAt = null;
                            $this->retryDelay = 1;
                        } else {
                            $this->retryAt = $this->clock->now()->modify('+'.$this->retryDelay.' seconds');
                            $this->retryDelay = min(30, 2 * $this->retryDelay);
                        }
                        if (!$sent && !$this->snapshotOutage) {
                            $this->logDrop($sessionId);
                        }
                        $this->snapshotOutage = !$sent;
                    } elseif ('session.snapshot' !== $kind && ($failed || ($eligible && !$sent))) {
                        $this->logDrop($sessionId);
                    }
                    if ('notification' === $kind) {
                        $this->settleNotice($sent);
                    }
                }
            } finally {
                $this->drain = null;
            }
        });
    }

    private function discardPending(): void
    {
        foreach ($this->pending as [$kind]) {
            if ('notification' === $kind) {
                $this->settleNotice(false);
            }
        }
        $this->pending = [];
    }

    private function settleNotice(bool $sent): void
    {
        $this->notice?->complete($sent);
        $this->notice = null;
    }

    private function logDrop(string $sessionId): void
    {
        $this->logger?->warning('hatfield_360.status_delivery_failed', [
            'component' => 'hatfield_360', 'event_type' => 'status_delivery_failed',
            'run_id' => $sessionId, 'session_id' => $sessionId,
        ]);
    }
}
