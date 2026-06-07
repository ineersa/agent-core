<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Auth;

use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Auth\CodexOAuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
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
 *   bin/console auth:codex --profile work
 *   bin/console auth:codex --refresh --profile work
 *
 * Profiles allow storing credentials for multiple OpenAI accounts.
 * Each profile stores under a separate key in auth.json
 * (e.g. 'openai-codex', 'openai-codex-work', 'openai-codex-personal').
 * Configure providers with 'auth_key' to select which stored credentials
 * to use.
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

        #[Option(description: 'Profile name for multiple accounts (e.g. "work", "personal"). Defaults to primary account.')]
        ?string $profile = null,

        ?OutputInterface $output = null,
    ): int {
        $io = new SymfonyStyle(new ArgvInput(), $output);

        try {
            $providerKey = CodexOAuthConfig::providerKeyForProfile($profile);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $profileLabel = (null !== $profile && '' !== trim($profile)) ? \sprintf(' (profile: %s)', $profile) : '';

        if ($refresh) {
            return $this->handleRefresh($io, $providerKey, $profileLabel);
        }

        return $this->handleLogin($io, $noBrowser, $timeout, $port, $providerKey, $profileLabel);
    }

    private function handleLogin(SymfonyStyle $io, bool $noBrowser, int $timeout, int $port, string $providerKey, string $profileLabel = ''): int
    {
        try {
            $record = $this->oauthService->login(
                io: $io,
                noBrowser: $noBrowser,
                timeout: $timeout,
                port: $port,
                providerKey: $providerKey,
            );
        } catch (\RuntimeException $e) {
            $io->error(\sprintf('Authentication failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $expiresAt = date('Y-m-d H:i:s T', $record->expires);

        $io->success(\sprintf(
            'OpenAI Codex authentication successful%s. Token expires at %s.',
            $profileLabel,
            $expiresAt,
        ));

        return Command::SUCCESS;
    }

    private function handleRefresh(SymfonyStyle $io, string $providerKey, string $profileLabel = ''): int
    {
        try {
            $record = $this->oauthService->refreshCredentials($providerKey);
        } catch (\RuntimeException $e) {
            $io->error(\sprintf('Token refresh failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $expiresAt = date('Y-m-d H:i:s T', $record->expires);

        $io->success(\sprintf(
            'Token refreshed successfully%s. New token expires at %s.',
            $profileLabel,
            $expiresAt,
        ));

        return Command::SUCCESS;
    }
}
