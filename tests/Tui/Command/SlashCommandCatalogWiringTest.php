<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Listener\PromptTemplateCommandRegistrar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Container wiring of the command catalog seeding order: built-in registrars
 * run when the catalog is constructed (before extensions can register through
 * CommandRegistryInterface), while prompt-template registration is left to
 * InteractiveMode, after extensions.
 */
#[CoversClass(SlashCommandCatalog::class)]
final class SlashCommandCatalogWiringTest extends IsolatedKernelTestCase
{
    #[Test]
    #[TestWith(['r', 'resume'])]
    #[TestWith(['cp', 'copy'])]
    #[TestWith(['m', 'model'])]
    #[TestWith(['main', 'agents-main'])]
    public function containerCatalogIsSeededWithBuiltInRegistrarsAtConstruction(string $alias, string $canonical): void
    {
        $this->assertSame($canonical, $this->catalog()->resolveName($alias));
    }

    #[Test]
    #[TestWith(['r'])]
    #[TestWith(['resume'])]
    #[TestWith(['cp'])]
    public function extensionRegisteringBuiltInNameOrAliasThrows(string $name): void
    {
        /** @var CommandRegistryInterface $registry */
        $registry = static::getContainer()->get(CommandRegistryInterface::class);

        $this->expectException(\InvalidArgumentException::class);

        $registry->register(
            new CommandDefinitionDTO(name: $name),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void
                {
                }
            },
        );
    }

    #[Test]
    public function promptTemplatesAreNotSeededAtConstructionAndSkipBuiltInNamesLater(): void
    {
        $catalog = $this->catalog();
        $this->assertFalse($catalog->has('template-only'));

        /** @var PromptTemplateCommandRegistrar $registrar */
        $registrar = static::getContainer()->get(PromptTemplateCommandRegistrar::class);
        $registrar->registerCatalog($catalog);

        $this->assertTrue($catalog->has('template-only'));
        $this->assertSame('/resume [session id]', $catalog->getMetadata('resume')?->usage);
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        $prompts = $classCwd.'/.hatfield/prompts';
        mkdir($prompts, 0o750, true);
        file_put_contents($prompts.'/resume.md', "---\ndescription: Template resume\n---\nBody\n");
        file_put_contents($prompts.'/template-only.md', "---\ndescription: Template only\n---\nBody\n");
    }

    private function catalog(): SlashCommandCatalog
    {
        /** @var SlashCommandCatalog $catalog */
        $catalog = static::getContainer()->get(SlashCommandCatalog::class);

        return $catalog;
    }
}
