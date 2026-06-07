<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodexOAuthConfigTest extends TestCase
{
    #[DataProvider('validProfileProvider')]
    public function testProviderKeyForProfileWithValidNames(?string $profile, string $expectedKey): void
    {
        $key = CodexOAuthConfig::providerKeyForProfile($profile);
        $this->assertSame($expectedKey, $key);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string}>
     */
    public static function validProfileProvider(): iterable
    {
        yield 'null defaults to openai-codex' => [null, 'openai-codex'];
        yield 'empty string defaults to openai-codex' => ['', 'openai-codex'];
        // spaces-only are trimmed to empty and default to openai-codex
        yield 'simple profile name' => ['work', 'openai-codex-work'];
        yield 'hyphenated profile' => ['my-account', 'openai-codex-my-account'];
        yield 'underscore profile' => ['personal_2', 'openai-codex-personal_2'];
        yield 'mixed alphanumeric' => ['acct2', 'openai-codex-acct2'];
        yield 'normalized to lowercase' => ['Work', 'openai-codex-work'];
        yield 'mixed case normalized' => ['MyProfile', 'openai-codex-myprofile'];
        yield 'digits only' => ['2', 'openai-codex-2'];
    }

    #[DataProvider('invalidProfileProvider')]
    public function testProviderKeyForProfileRejectsInvalidNames(string $profile): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid profile name');

        CodexOAuthConfig::providerKeyForProfile($profile);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidProfileProvider(): iterable
    {
        yield 'contains slash' => ['work/personal'];
        yield 'contains backslash' => ['work\\personal'];
        yield 'contains dot' => ['personal.'];
        yield 'double dot' => ['..'];
        yield 'contains space' => ['my profile'];
        yield 'leading dot' => ['.hidden'];
        yield 'contains whitespace' => ['work personal'];
        yield 'contains special chars' => ['work@home'];
        yield 'contains dollar' => ['acct$'];
    }

    public function testProviderKeyForProfileReturnsDefaultForBlank(): void
    {
        // Blank/null/empty return default, they don't throw
        $this->assertSame('openai-codex', CodexOAuthConfig::providerKeyForProfile('   '));
        $this->assertSame('openai-codex', CodexOAuthConfig::providerKeyForProfile(''));
        $this->assertSame('openai-codex', CodexOAuthConfig::providerKeyForProfile(null));
    }

    public function testProviderKeyForProfileNormalizesToLowercase(): void
    {
        $key = CodexOAuthConfig::providerKeyForProfile('MyWork');
        $this->assertSame('openai-codex-mywork', $key);
    }
}
