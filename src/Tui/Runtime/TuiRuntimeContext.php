<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Screen\ChatScreen;
use Symfony\Component\Tui\Tui;

/**
 * Per-run runtime context passed to listener registrars.
 *
 * Carries all the services and state a listener registrar needs
 * to attach closures or event listeners to the TUI instance.
 *
 * The {@see TuiSessionSwitchServiceInterface} is bound per-iteration
 * and enables slash commands to request session switches.
 *
 * The {@see TuiSessionLifecycleDispatcher} is created fresh each
 * iteration so subscriptions never leak across sessions.  Future
 * slash-command handlers and extensions subscribe to lifecycle
 * events (session start, resume, draft, end) through this dispatcher.
 */
final readonly class TuiRuntimeContext
{
    public function __construct(
        public Tui $tui,
        public AgentSessionClient $client,
        public TuiSessionState $state,
        public ChatScreen $screen,
        public HatfieldSessionStore $sessionStore,
        public TuiTickDispatcher $ticks,
        public TuiSessionSwitchServiceInterface $switch,
        public TuiSessionLifecycleDispatcher $lifecycle,
    ) {
    }
}
