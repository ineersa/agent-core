<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrates the xAI Grok CLI OAuth PKCE login flow.
 *
 * Same UX as {@see CodexOAuthService}: browser loopback + manual paste.
 * Uses {@see CodexOAuthProvider} against auth.x.ai (approval_prompt strip +
 * empty client_secret omit are provider-agnostic).
 */
final class GrokOAuthService
{
    public function __construct(
        private GrokAuthStorage $storage,
        private ?GrokTokenRefresher $tokenRefresher = null,
    ) {
    }

    /**
     * Run the full OAuth PKCE login flow.
     *
     * @throws \RuntimeException on any step failure
     */
    public function login(
        SymfonyStyle $io,
        bool $noBrowser = false,
        int $timeout = GrokOAuthConfig::DEFAULT_TIMEOUT,
        int $port = GrokOAuthConfig::DEFAULT_PORT,
        string $providerKey = GrokOAuthConfig::PROVIDER_KEY,
    ): GrokAuthRecord {
        $provider = $this->createProvider($port);
        $authUrl = $provider->getAuthorizationUrl([
            'scope' => GrokOAuthConfig::SCOPE,
        ]);

        $expectedState = $provider->getState();
        $pkceVerifier = $provider->getPkceCode();

        $io->writeln('');
        $io->writeln('  <info>xAI Grok CLI Authorization</info>');
        $io->writeln('');
        $io->writeln('  A browser window should open. If not, visit:');
        $io->writeln(\sprintf('  <href=%s>%s</>', $authUrl, $authUrl));
        $io->writeln('');

        $server = new LocalCallbackServer();
        $callbackResult = $server->waitForCallback(
            $expectedState,
            (float) $timeout,
            $port,
            static function () use ($noBrowser, $authUrl): void {
                if (!$noBrowser) {
                    BrowserLauncher::open($authUrl);
                }
            },
            GrokOAuthConfig::CALLBACK_PATH,
        );

        if (null === $callbackResult) {
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
        } else {
            $code = $callbackResult['code'];
            $io->writeln('  <info>✓</info> Authorization callback received.');
        }

        if (null === $code || '' === $code) {
            throw new \RuntimeException('No authorization code obtained.');
        }

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

        $record = new GrokAuthRecord(
            access: $accessToken,
            refresh: $refreshToken,
            expires: $expires,
        );

        $this->storage->saveCredentials($providerKey, $record);

        return $record;
    }

    /**
     * Refresh stored credentials for the given provider key.
     *
     * @throws \RuntimeException when no stored credentials or refresh fails
     */
    public function refreshCredentials(string $providerKey = GrokOAuthConfig::PROVIDER_KEY): GrokAuthRecord
    {
        if (null === $this->tokenRefresher) {
            throw new \RuntimeException('Token refresh is not available (no refresher configured).');
        }

        $stored = $this->storage->loadCredentialsRaw($providerKey);

        if (null === $stored) {
            $hint = GrokOAuthConfig::authCommandHint();
            throw new \RuntimeException(\sprintf('No stored Grok credentials found. Run %s first.', $hint));
        }

        try {
            $fresh = $this->tokenRefresher->refresh($stored->refresh);
        } catch (\Throwable $e) {
            $hint = GrokOAuthConfig::authCommandHint();

            throw new \RuntimeException("Token refresh failed for stored Grok credentials. Run {$hint} to re-authenticate.", previous: $e);
        }

        $this->storage->saveCredentials($providerKey, $fresh);

        return $fresh;
    }

    /**
     * Reuses CodexOAuthProvider: league's GenericProvider injects approval_prompt
     * and an empty client_secret; both break xAI token exchange the same way they
     * break OpenAI Hydra. Do not clone — the two fixes are provider-agnostic.
     */
    private function createProvider(int $port = GrokOAuthConfig::DEFAULT_PORT): CodexOAuthProvider
    {
        return new CodexOAuthProvider(GrokOAuthConfig::providerOptions($port));
    }
}
