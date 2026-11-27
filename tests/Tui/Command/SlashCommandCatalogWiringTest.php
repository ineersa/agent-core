<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
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
 * Container wiring of the command catalog seeding order: built-in commands
 * are static data registered when the catalog is constructed (before
 * extensions can register through CommandRegistryInterface) without building
 * any service, while dynamic commands are left to InteractiveMode, after
 * extensions and CLI options.
 *
 * The kernel is shared by the methods of this class, so the skill discovery
 * proof runs first, before any other method could construct SkillDiscovery.
 */
#[CoversClass(SlashCommandCatalog::class)]
final class SlashCommandCatalogWiringTest extends IsolatedKernelTestCase
{
    /**
     * Extensions load before AgentCommand applies --no-skills/--skills-path,
     * so building the catalog for them must not discover (and cache) skills.
     */
    #[Test]
    public function buildingCatalogForExtensionsLeavesSkillDiscoveryToCliOptions(): void
    {
        $container = static::getContainer();
        $this->extensionRegistry();

        $this->assertTrue($container->initialized(SlashCommandCatalog::class));
        $this->assertFalse($container->initialized(SkillDiscovery::class));

        /** @var SkillsConfig $config */
        $config = $container->get(SkillsConfig::class);
        $config->noSkills = true;
        /** @var SkillDiscovery $discovery */
        $discovery = $container->get(SkillDiscovery::class);

        $this->assertNull($discovery->findByName('project-skill'));
    }

    #[Test]
    #[TestWith(['r'])]
    #[TestWith(['resume'])]
    #[TestWith(['cp'])]
    #[TestWith(['m'])]
    #[TestWith(['main'])]
    public function extensionRegisteringBuiltInNameOrAliasThrows(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->extensionRegistry()->register(
            new CommandDefinitionDTO(name: $name),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void
                {
                }
            },
        );
    }

    #[Test]
    public function promptTemplatesAreAddedLaterAndSkipBuiltInNames(): void
    {
        /** @var SlashCommandCatalog $catalog */
        $catalog = static::getContainer()->get(SlashCommandCatalog::class);
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

        $skill = $classCwd.'/.hatfield/skills/project-skill';
        mkdir($skill, 0o750, true);
        file_put_contents($skill.'/SKILL.md', "---\nname: project-skill\ndescription: Project skill\n---\nBody\n");
    }

    private function extensionRegistry(): CommandRegistryInterface
    {
        /** @var CommandRegistryInterface $registry */
        $registry = static::getContainer()->get(CommandRegistryInterface::class);

        return $registry;
    }
}
