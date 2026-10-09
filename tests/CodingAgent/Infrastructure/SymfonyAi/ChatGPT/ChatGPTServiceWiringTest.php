<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\ChatGPT;

use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ChatGPT\ChatGPTSymfonyAiProviderBuilder;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthCommand;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

final class ChatGPTServiceWiringTest extends PerMethodIsolatedKernelTestCase
{
    public function testFreshKernelRegistersAuthCommandAndHostStorageBeforeLogin(): void
    {
        $container = self::getContainer();
        $loader = $container->get('console.command_loader');
        $this->assertInstanceOf(CommandLoaderInterface::class, $loader);
        $this->assertTrue($loader->has('auth:chatgpt'));
        $this->assertFalse($loader->has('auth:codex'));
        // Constructing these services must not read a grant or require network
        // access. Their storage operations happen only on explicit auth/inference.
        $this->assertInstanceOf(AuthFileStore::class, $container->get(AuthStorageInterface::class));
        $this->assertInstanceOf(AuthCommand::class, $container->get(AuthCommand::class));
        $this->assertInstanceOf(ChatGPTSymfonyAiProviderBuilder::class, $container->get(ChatGPTSymfonyAiProviderBuilder::class));
    }
}
