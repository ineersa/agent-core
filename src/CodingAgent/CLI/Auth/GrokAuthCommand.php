<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Auth;

use Ineersa\CodingAgent\Auth\GrokOAuthConfig;
use Ineersa\CodingAgent\Auth\GrokOAuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command to authenticate with xAI Grok CLI via OAuth PKCE.
 *
 * Usage:
 *   bin/console auth:grok
 *   bin/console auth:grok --no-browser
 *   bin/console auth:grok --timeout=600 --port=56122
 *   bin/console auth:grok --refresh
 *
 * No multi-account profiles (out of scope). Credentials land under key
 * 'grok-cli' in ~/.hatfield/auth.json.
 */
#[AsCommand(name: 'auth:grok', description: 'Authenticate with xAI Grok CLI subscription (OAuth PKCE)')]
final class GrokAuthCommand
{
    public function __construct(
        private readonly GrokOAuthService $oauthService,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Skip browser auto-open, show URL for manual visit')]
        bool $noBrowser = false,

        #[Option(description: 'Timeout in seconds for the callback server')]
        int $timeout = 300,

        #[Option(description: 'Local TCP port for the OAuth callback server (default: 56122)')]
        int $port = 56122,

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
                providerKey: GrokOAuthConfig::PROVIDER_KEY,
            );
        } catch (\RuntimeException $e) {
            $io->error(\sprintf('Authentication failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $expiresAt = date('Y-m-d H:i:s T', $record->expires);

        $io->success(\sprintf(
            'xAI Grok CLI authentication successful. Token expires at %s.',
            $expiresAt,
        ));

        return Command::SUCCESS;
    }

    private function handleRefresh(SymfonyStyle $io): int
    {
        try {
            $record = $this->oauthService->refreshCredentials(GrokOAuthConfig::PROVIDER_KEY);
        } catch (\RuntimeException $e) {
            $io->error(\sprintf('Token refresh failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $expiresAt = date('Y-m-d H:i:s T', $record->expires);

        $io->success(\sprintf(
            'Token refreshed successfully. New token expires at %s.',
            $expiresAt,
        ));

        return Command::SUCCESS;
    }
}
