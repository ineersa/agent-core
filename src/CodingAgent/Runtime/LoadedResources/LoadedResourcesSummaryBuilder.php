<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\LoadedResources;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateLoader;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceConflictDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceItemDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\ThemeLoadedResourcesProviderInterface;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;

/**
 * Aggregates discovery/load results into a display-only startup summary DTO.
 */
final readonly class LoadedResourcesSummaryBuilder implements LoadedResourcesSummaryProviderInterface
{
    public function __construct(
        private AgentsContextDiscovery $agentsContextDiscovery,
        private SkillDiscovery $skillDiscovery,
        private PromptTemplateLoader $promptTemplateLoader,
        private AgentDefinitionDiscovery $agentDefinitionDiscovery,
        private ThemeLoadedResourcesProviderInterface $themeLoadedResourcesProvider,
        private ExtensionManager $extensionManager,
        private ?AiCatalog $aiCatalog = null,
        private ?AppConfig $appConfig = null,
    ) {
    }

    public function build(): LoadedResourcesSummaryDTO
    {
        $sections = [
            $this->buildContextSection(),
            $this->buildSkillsSection(),
            $this->buildPromptsSection(),
            $this->buildThemesSection(),
            $this->buildAgentsSection(),
            $this->buildExtensionsSection(),
        ];

        $catalogSection = $this->buildAiCatalogSection();
        if (null !== $catalogSection) {
            $sections[] = $catalogSection;
        }

        $defaultModelSection = $this->buildDefaultModelSection();
        if (null !== $defaultModelSection) {
            $sections[] = $defaultModelSection;
        }

        return new LoadedResourcesSummaryDTO($sections);
    }

    private function buildAiCatalogSection(): ?LoadedResourceSectionDTO
    {
        if (true !== $this->aiCatalog?->isBundledNewerThanUser()) {
            return null;
        }

        // Message-only conflict branch in LoadedResourcesWidget::formatConflict
        // (empty winnerPath + message) → "⚠ AI Catalog: update available — …"
        return new LoadedResourceSectionDTO(
            key: 'ai-catalog',
            label: 'AI Catalog',
            items: [],
            conflicts: [
                new LoadedResourceConflictDTO(
                    name: 'AI Catalog',
                    winnerPath: '',
                    loserPath: '',
                    message: 'update available — run `hatfield providers:update`',
                ),
            ],
        );
    }

    private function buildDefaultModelSection(): ?LoadedResourceSectionDTO
    {
        $stale = $this->appConfig?->staleDefaultModel;
        if (null === $stale || '' === $stale) {
            return null;
        }

        // appConfig is non-null here: staleDefaultModel can only be set when it is.
        $effective = $this->appConfig->ai?->defaultModel;
        $message = \sprintf('unavailable — using %s', $effective);

        // Message-only conflict branch → "⚠ <stale>: unavailable — using <effective>"
        // ('Default model' is the section label, not part of the ⚠ line.)
        return new LoadedResourceSectionDTO(
            key: 'default-model',
            label: 'Default model',
            items: [],
            conflicts: [
                new LoadedResourceConflictDTO(
                    name: $stale,
                    winnerPath: '',
                    loserPath: '',
                    message: $message,
                ),
            ],
        );
    }

    private function buildContextSection(): LoadedResourceSectionDTO
    {
        $items = [];
        foreach ($this->agentsContextDiscovery->discoverPaths() as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $items[] = new LoadedResourceItemDTO(
                name: '' !== basename($path) ? basename($path) : 'AGENTS.md',
                sourcePath: $path,
            );
        }

        return new LoadedResourceSectionDTO(
            key: 'context',
            label: 'Context',
            items: $items,
        );
    }

    private function buildSkillsSection(): LoadedResourceSectionDTO
    {
        $items = [];
        foreach ($this->skillDiscovery->discover() as $skill) {
            $items[] = new LoadedResourceItemDTO(
                name: $skill->name,
                sourcePath: $skill->skillFile,
            );
        }

        $conflicts = [];
        foreach ($this->skillDiscovery->getCollisions() as $collision) {
            $conflicts[] = new LoadedResourceConflictDTO(
                name: (string) ($collision['name'] ?? ''),
                winnerPath: (string) ($collision['winner'] ?? ''),
                loserPath: (string) ($collision['ignored'] ?? ''),
            );
        }

        return new LoadedResourceSectionDTO(
            key: 'skills',
            label: 'Skills',
            items: $items,
            conflicts: $conflicts,
        );
    }

    private function buildPromptsSection(): LoadedResourceSectionDTO
    {
        $result = $this->promptTemplateLoader->load();
        $items = [];
        foreach ($result->templates as $template) {
            $items[] = new LoadedResourceItemDTO(
                name: $template->name,
                sourcePath: $template->filePath,
            );
        }

        $conflicts = [];
        foreach ($result->diagnostics as $diagnostic) {
            if ('collision' !== $diagnostic->type) {
                continue;
            }
            $conflicts[] = new LoadedResourceConflictDTO(
                name: $diagnostic->name,
                winnerPath: $diagnostic->winnerPath,
                loserPath: $diagnostic->loserPath,
                message: $diagnostic->message,
            );
        }

        return new LoadedResourceSectionDTO(
            key: 'prompts',
            label: 'Prompts',
            items: $items,
            conflicts: $conflicts,
        );
    }

    private function buildThemesSection(): LoadedResourceSectionDTO
    {
        return new LoadedResourceSectionDTO(
            key: 'themes',
            label: 'Themes',
            items: $this->themeLoadedResourcesProvider->getLoadedThemeResourceItems(),
            conflicts: $this->themeLoadedResourcesProvider->getThemeResourceConflicts(),
        );
    }

    private function buildAgentsSection(): LoadedResourceSectionDTO
    {
        $catalog = $this->agentDefinitionDiscovery->discover();
        $items = [];
        foreach ($catalog->all() as $agent) {
            $items[] = new LoadedResourceItemDTO(
                name: $agent->name,
                sourcePath: $agent->sourcePath,
            );
        }

        $conflicts = [];
        foreach ($catalog->diagnostics() as $diagnostic) {
            if ('collision' !== $diagnostic->type) {
                continue;
            }
            $conflicts[] = new LoadedResourceConflictDTO(
                name: $diagnostic->name,
                winnerPath: $diagnostic->winnerPath,
                loserPath: $diagnostic->loserPath,
                message: $diagnostic->message,
            );
        }

        return new LoadedResourceSectionDTO(
            key: 'agents',
            label: 'Agents',
            items: $items,
            conflicts: $conflicts,
        );
    }

    private function buildExtensionsSection(): LoadedResourceSectionDTO
    {
        $items = [];
        $conflicts = [];
        foreach ($this->extensionManager->getLoadOutcomes() as $outcome) {
            $items[] = new LoadedResourceItemDTO(
                name: $outcome->className,
                sourcePath: '',
                disabled: !$outcome->loaded,
            );
            if (!$outcome->loaded) {
                $conflicts[] = new LoadedResourceConflictDTO(
                    name: $outcome->className,
                    winnerPath: '',
                    loserPath: $outcome->className,
                    message: $outcome->errorMessage,
                );
            }
        }

        return new LoadedResourceSectionDTO(
            key: 'extensions',
            label: 'Extensions',
            items: $items,
            conflicts: $conflicts,
        );
    }
}
