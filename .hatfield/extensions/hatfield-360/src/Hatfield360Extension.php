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
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiBackgroundSnapshotObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionCloseObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionMetadataContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionObserverInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function Amp\async;

final class Hatfield360Extension implements ConfigurableExtensionInterface, TuiExtensionInterface, TuiSessionObserverInterface, TuiRunObserverInterface, TuiSessionCloseObserverInterface, TuiBackgroundSnapshotObserverInterface, ExtensionCommandHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const int MAX_TERMINALS = 1024;

    // /reload replaces the extension instance, not the explicit launch.
    private static int $sequence = 0;
    private ?StatusClient $client = null;
    private bool $canBootstrap = false;
    private string $sessionId = '';
    private ?TuiSessionMetadataContextInterface $metadata = null;
    private ?string $title = null;
    private int $epoch = 0;
    private bool $closing = false;
    /** @var array{id: string, status: string}|null */
    private ?array $turn = null;
    /** @var array<string, array{id: string, reason: string}> */
    private array $waits = [];
    /** @var array<string, array<string, array{id: string, reason: string}>> */
    private array $childWaits = [];
    /** @var array<string, string> */
    private array $tools = [];
    private ?int $activeSubagents = null;
    private ?int $backgroundScripts = null;
    /** @var array<string, int> */
    private array $childTerminals = [];
    /** @var Future<void>|null */
    private ?Future $drain = null;
    /** @var list<array{string, string, int, int, array<string, mixed>}> */
    private array $pending = [];
    /** @var DeferredFuture<bool>|null */
    private ?DeferredFuture $notice = null;

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
        $this->metadata = $context instanceof TuiSessionMetadataContextInterface ? $context : null;
    }

    public function onTuiSessionSelected(string $sessionId, bool $resumed = false): void
    {
        if ($this->closing || (null === $this->client && !$this->canBootstrap)) {
            return;
        }

        $this->sessionId = $sessionId;
        $this->refreshTitle();
        $this->epoch = ++self::$sequence;
        $this->turn = null;
        $this->waits = [];
        $this->childWaits = [];
        $this->tools = [];
        $this->activeSubagents = null;
        $this->backgroundScripts = null;
        $this->childTerminals = [];
        $this->snapshot($this->epoch);
    }

    public function onTuiSessionClosing(string $sessionId): void
    {
        if ($this->closing || '' === $sessionId || $this->sessionId !== $sessionId) {
            return;
        }
        $this->closing = true;
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

    public function onTuiRunObserved(TuiRunObservationDTO $observation): void
    {
        if (!$this->observes($observation->sessionId)) {
            return;
        }
        $type = $observation->type;
        if (null !== $observation->childRunId) {
            if (!$this->observeChild($observation, $observation->childRunId)) {
                return;
            }
        } elseif (TuiRunObservationTypeEnum::TurnStarted === $type) {
            // Internal model/tool steps stay in the same logical response.
            if ('working' === ($this->turn['status'] ?? null)) {
                return;
            }
            $this->refreshTitle();
            $this->turn = ['id' => (string) (self::$sequence + 1), 'status' => 'working'];
            $this->waits = [];
            $this->tools = [];
        } elseif (null !== $this->turn && 'working' === $this->turn['status']) {
            $outcome = match ($type) {
                TuiRunObservationTypeEnum::TurnCompleted => 'completed',
                TuiRunObservationTypeEnum::TurnFailed => 'failed',
                TuiRunObservationTypeEnum::TurnCancelled => 'cancelled',
                default => null,
            };
            if (null !== $outcome) {
                if ('completed' === $outcome && 0 === $this->activeSubagents) {
                    // A zero sampled before the response finished cannot settle it.
                    $this->activeSubagents = null;
                }
                $this->turn['status'] = $outcome;
                $this->waits = [];
                $this->tools = [];
            } elseif (null !== $observation->requestId) {
                $key = hash('sha256', $observation->requestId);
                if (TuiRunObservationTypeEnum::ToolStarted === $type && null !== $observation->toolName
                    && mb_check_encoding($observation->toolName, 'UTF-8') && mb_strlen($observation->toolName, 'UTF-8') <= 128
                    && !isset($this->tools[$key]) && \count($this->tools) < 32) {
                    $this->tools[$key] = $observation->toolName;
                } elseif (TuiRunObservationTypeEnum::ToolFinished === $type && isset($this->tools[$key])) {
                    unset($this->tools[$key]);
                } elseif (TuiRunObservationTypeEnum::QuestionRequested === $type || TuiRunObservationTypeEnum::ApprovalRequested === $type) {
                    if (isset($this->waits[$key]) || \count($this->waits) >= 16) {
                        return;
                    }
                    $this->waits[$key] = ['id' => (string) (self::$sequence + 1), 'reason' => TuiRunObservationTypeEnum::ApprovalRequested === $type ? 'permission' : 'question'];
                } elseif (TuiRunObservationTypeEnum::InputResolved === $type && isset($this->waits[$key])) {
                    unset($this->waits[$key]);
                } else {
                    return;
                }
            } else {
                return;
            }
        } else {
            return;
        }
        $this->snapshot(++self::$sequence);
    }

    public function onTuiBackgroundSnapshot(TuiBackgroundSnapshotDTO $snapshot): void
    {
        if (!$this->observes($snapshot->sessionId)) {
            return;
        }
        $children = $snapshot->activeSubagents > 1024 ? null : $snapshot->activeSubagents;
        $scripts = $snapshot->backgroundScripts > 1024 ? null : $snapshot->backgroundScripts;
        if ($children === $this->activeSubagents && $scripts === $this->backgroundScripts) {
            return;
        }
        $this->activeSubagents = $children;
        $this->backgroundScripts = $scripts;
        $this->snapshot(++self::$sequence);
    }

    private function refreshTitle(): void
    {
        $this->title = null;
        if ($this->metadata?->getSessionId() !== $this->sessionId) {
            return;
        }
        try {
            $title = $this->metadata->getSessionDisplayTitle();
        } catch (\Throwable) {
            return;
        }
        if (null !== $title && mb_check_encoding($title, 'UTF-8')) {
            $this->title = mb_substr($title, 0, 1024, 'UTF-8');
        }
    }

    private function observeChild(TuiRunObservationDTO $observation, string $id): bool
    {
        $type = $observation->type;
        if ($observation->sequence <= ($this->childTerminals[$id] ?? 0)) {
            return false;
        }
        if (TuiRunObservationTypeEnum::ChildExecutionEnded === $type) {
            if (isset($this->childTerminals[$id]) || \count($this->childTerminals) < self::MAX_TERMINALS) {
                $this->childTerminals[$id] = $observation->sequence;
            }
            $changed = isset($this->childWaits[$id]);
            unset($this->childWaits[$id]);

            return $changed;
        }
        if (null === $observation->requestId) {
            return false;
        }
        $key = $observation->requestId;
        if (TuiRunObservationTypeEnum::InputResolved === $type && isset($this->childWaits[$id][$key])) {
            unset($this->childWaits[$id][$key]);
            if ([] === $this->childWaits[$id]) {
                unset($this->childWaits[$id]);
            }

            return true;
        }
        if (TuiRunObservationTypeEnum::QuestionRequested !== $type && TuiRunObservationTypeEnum::ApprovalRequested !== $type) {
            return false;
        }
        if (isset($this->childWaits[$id][$key]) || \count($this->childWaits[$id] ?? []) >= 16
            || (!isset($this->childWaits[$id]) && \count($this->childWaits) >= 1024)) {
            return false;
        }
        $this->childWaits[$id][$key] = ['id' => (string) (self::$sequence + 1), 'reason' => TuiRunObservationTypeEnum::ApprovalRequested === $type ? 'permission' : 'question'];

        return true;
    }

    private function observes(string $sessionId): bool
    {
        return !$this->closing && '' !== $this->sessionId && $sessionId === $this->sessionId;
    }

    private function snapshot(int $sequence): void
    {
        // Prefer foreground input, then the oldest observed child episode.
        $attention = $this->waits[array_key_first($this->waits) ?? ''] ?? null;
        if (null === $attention) {
            foreach ($this->childWaits as $waits) {
                $candidate = $waits[array_key_first($waits) ?? ''] ?? null;
                if (null !== $candidate && (null === $attention || (int) $candidate['id'] < (int) $attention['id'])) {
                    $attention = $candidate;
                }
            }
        }
        $this->enqueue('session.snapshot', $sequence, [
            'provider' => 'hatfield', 'title' => $this->title, 'turn' => $this->turn, 'attention' => $attention,
            'activeSubagents' => $this->activeSubagents,
            'backgroundScripts' => $this->backgroundScripts, 'tool' => $this->tools[array_key_last($this->tools) ?? ''] ?? null,
        ]);
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
                    $client = $this->client ??= ($this->closing ? null : HatfieldProcessIdentityProvider::register());
                    $eligible = $this->epoch === $epoch && (!$this->closing || 'session.ended' === $kind);
                    $sent = $eligible && null !== $client && $client->send($kind, $sessionId, $sequence, $epoch, $fields);
                    if ($eligible && !$sent) {
                        $this->logDrop($sessionId);
                    }
                    if ('notification' === $kind) {
                        $this->settleNotice($sent);
                    }
                }
            } catch (\Throwable) {
                $this->logDrop($this->sessionId);
                $this->discardPending();
                $this->settleNotice(false);
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
