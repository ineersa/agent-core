<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrates the OpenAI Codex OAuth PKCE login flow.
 *
 * Wraps league/oauth2-client for PKCE generation, authorization URL
 * construction, and token exchange, while the CLI-specific pieces
 * (callback server, browser launch, manual paste) are handled by
 * dedicated Hatfield services.
 */
final class CodexOAuthService
{
    public function __construct(
        private CodexAuthStorage $storage,
    ) {
    }

    /**
     * Run the full OAuth PKCE login flow.
     *
     * 1. Build the authorization URL with PKCE challenge
     * 2. Start the local callback server on 127.0.0.1:$port
     * 3. Print the URL and try to open the browser
     * 4. Race callback vs manual paste input
     * 5. Exchange the authorization code for tokens
     * 6. Extract the chatgpt_account_id from the JWT
     * 7. Persist credentials to auth.json
     * 8. Return the record (callers must NOT echo raw tokens)
     *
     * @throws \RuntimeException on any step failure
     */
    public function login(
        ?SymfonyStyle $io = null,
        bool $noBrowser = false,
        int $timeout = CodexOAuthConfig::DEFAULT_TIMEOUT,
        int $port = CodexOAuthConfig::DEFAULT_PORT,
    ): CodexAuthRecord {
        $io ??= new SymfonyStyle(new ArgvInput(), null);

        $provider = $this->createProvider($port);
        $authUrl = $provider->getAuthorizationUrl([
            'scope' => CodexOAuthConfig::SCOPE,
            'originator' => CodexOAuthConfig::ORIGINATOR,
            'codex_cli_simplified_flow' => 'true',
            'id_token_add_organizations' => 'true',
        ]);

        $expectedState = $provider->getState();
        $pkceVerifier = $provider->getPkceCode();

        // Try browser callback if not disabled
        $code = null;

        if (!$noBrowser) {
            BrowserLauncher::open($authUrl);
        }

        $io->writeln('');
        $io->writeln('  <info>OpenAI Codex Authorization</info>');
        $io->writeln('');
        $io->writeln('  A browser window should open. If not, visit:');
        $io->writeln(\sprintf('  <href=%s>%s</>', $authUrl, $authUrl));
        $io->writeln('');

        // Wait for callback via local server
        $server = new LocalCallbackServer();
        $callbackResult = $server->waitForCallback($expectedState, (float) $timeout, $port);

        if (null !== $callbackResult) {
            $code = $callbackResult['code'];
            $io->writeln('  <info>✓</info> Authorization callback received.');
        } else {
            $io->writeln('  Could not detect browser callback automatically.');
            $io->writeln('  Paste the redirect URL (or just the authorization code) below:');
            $io->writeln('');

            $input = (string) $io->ask('  Authorization code / URL', null, static function (?string $v) {
                if (null === $v || '' === trim($v)) {
                    throw new \RuntimeException('Authorization input is required.');
                }

                return trim($v);
            });

            $parsed = ManualCodeParser::parse($input);
            if (null !== $parsed['state'] && $parsed['state'] !== $expectedState) {
                throw new \RuntimeException('State mismatch in manual paste input. Please try again.');
            }

            $code = $parsed['code'];
        }

        if (null === $code || '' === $code) {
            throw new \RuntimeException('No authorization code obtained.');
        }

        // Exchange authorization code for tokens
        try {
            $provider->setPkceCode($pkceVerifier);
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (IdentityProviderException $e) {
            throw new \RuntimeException(\sprintf('Token exchange failed: %s', $e->getMessage()), previous: $e);
        }

        $accessToken = $token->getToken();
        $refreshToken = $token->getRefreshToken();
        $expires = $token->getExpires();

        if (null === $accessToken || null === $refreshToken || null === $expires) {
            throw new \RuntimeException('Token exchange response missing required fields (access, refresh, expires).');
        }

        // Extract account ID from JWT
        $accountId = CodexAccountIdExtractor::extract($accessToken);
        if (null === $accountId) {
            throw new \RuntimeException('Failed to extract chatgpt_account_id from the access token JWT.');
        }

        // Persist
        $record = new CodexAuthRecord(
            access: $accessToken,
            refresh: $refreshToken,
            expires: $expires,
            accountId: $accountId,
        );

        $this->storage->saveCredentials(CodexOAuthConfig::PROVIDER_KEY, $record);

        return $record;
    }

    /**
     * Refresh stored credentials for the Codex provider.
     *
     * Loads the stored refresh token, exchanges it for new tokens,
     * extracts the account ID (should match stored), and persists.
     *
     * @throws \RuntimeException when no stored credentials, refresh fails, or account ID changes
     */
    public function refreshCredentials(): CodexAuthRecord
    {
        $stored = $this->storage->loadCredentials(CodexOAuthConfig::PROVIDER_KEY);

        if (null === $stored) {
            throw new \RuntimeException('No stored Codex credentials found. Run bin/console auth:codex first.');
        }

        $provider = $this->createProvider();

        try {
            $token = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $stored->refresh,
            ]);
        } catch (IdentityProviderException $e) {
            throw new \RuntimeException(\sprintf('Token refresh failed: %s. Run bin/console auth:codex to re-authenticate.', $e->getMessage()), previous: $e);
        }

        $accessToken = $token->getToken();
        $refreshToken = $token->getRefreshToken();
        $expires = $token->getExpires();

        if (null === $accessToken || null === $refreshToken || null === $expires) {
            throw new \RuntimeException('Token refresh response missing required fields.');
        }

        $accountId = CodexAccountIdExtractor::extract($accessToken);
        if (null === $accountId) {
            throw new \RuntimeException('Failed to extract account ID from refreshed token. Run bin/console auth:codex to re-authenticate.');
        }

        // Validate account ID hasn't changed — prevents silent credential theft
        if ($accountId !== $stored->accountId) {
            throw new \RuntimeException(\sprintf('Account ID changed from "%s" to "%s" after token refresh. Run bin/console auth:codex to re-authenticate.', $stored->accountId, $accountId));
        }

        $record = new CodexAuthRecord(
            access: $accessToken,
            refresh: $refreshToken,
            expires: $expires,
            accountId: $accountId,
        );

        $this->storage->saveCredentials(CodexOAuthConfig::PROVIDER_KEY, $record);

        return $record;
    }

    /**
     * Get a configured GenericProvider for Codex OAuth.
     */
    private function createProvider(int $port = CodexOAuthConfig::DEFAULT_PORT): GenericProvider
    {
        return new GenericProvider(CodexOAuthConfig::providerOptions($port));
    }
}
