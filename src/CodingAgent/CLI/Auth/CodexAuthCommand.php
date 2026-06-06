<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Auth;

use Ineersa\CodingAgent\Auth\CodexOAuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command to authenticate with OpenAI Codex via OAuth PKCE.
 *
 * Usage:
 *   bin/console auth:codex
 *   bin/console auth:codex --no-browser
 *   bin/console auth:codex --timeout=600 --port=1555
 *   bin/console auth:codex --refresh
 */
#[AsCommand(name: 'auth:codex', description: 'Authenticate with OpenAI Codex subscription (OAuth PKCE)')]
final class CodexAuthCommand
{
    public function __construct(
        private readonly CodexOAuthService $oauthService,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Skip browser auto-open, show URL for manual visit')]
        bool $noBrowser = false,

        #[Option(description: 'Timeout in seconds for the callback server')]
        int $timeout = 300,

        #[Option(description: 'Local TCP port for the OAuth callback server (default: 1455)')]
        int $port = 1455,

        #[Option(description: 'Refresh existing credentials instead of full login')]
        bool $refresh = false,

        ?OutputInterface $output = null,
    ): int {
        $io = new SymfonyStyle(new ArgvInput(), $output);

        if ($refresh) {
            return $this->handleRefresh($io);
        }

        return $this->handleLogin($io, $noBrowser, $timeout, $port);
    }

    private function handleLogin(SymfonyStyle $io, bool $noBrowser, int $timeout, int $port): int
    {
        try {
            $record = $this->oauthService->login(
                io: $io,
                noBrowser: $noBrowser,
                timeout: $timeout,
                port: $port,
            );
        } catch (\RuntimeException $e) {
            $io->error(\sprintf('Authentication failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $expiresAt = date('Y-m-d H:i:s T', (int) ($record->expires / 1000));

        $io->success(\sprintf(
            'OpenAI Codex authentication successful. Token expires at %s.',
            $expiresAt,
        ));

        return Command::SUCCESS;
    }

    private function handleRefresh(SymfonyStyle $io): int
    {
        try {
            $record = $this->oauthService->refreshCredentials();
        } catch (\RuntimeException $e) {
            $io->error(\sprintf('Token refresh failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $expiresAt = date('Y-m-d H:i:s T', (int) ($record->expires / 1000));

        $io->success(\sprintf(
            'Token refreshed successfully. New token expires at %s.',
            $expiresAt,
        ));

        return Command::SUCCESS;
    }
}
