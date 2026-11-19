<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsResolutionDTO;
use Ineersa\CodingAgent\Config\SettingsValueDTO;
use Ineersa\CodingAgent\Config\SetupSettingsService;
use Ineersa\Tui\Theme\ThemeRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class CoreSettingsScreen
{
    private const GROUPS = [
        'Appearance' => ['tui.theme', 'tui.transcript.previews.expanded_by_default'],
        'Delegation' => ['forks.enabled'],
        'Compaction' => ['compaction.auto_enabled', 'compaction.model', 'compaction.thinking_level'],
    ];

    private const HELP = [
        'tui.theme' => ['Color theme', 'Choose the colors used by Hatfield.'],
        'tui.transcript.previews.expanded_by_default' => ['Expand previews', 'Show tool output and diffs expanded by default.'],
        'forks.enabled' => ['Allow forks', 'Let the assistant branch the conversation into a child run.'],
        'compaction.auto_enabled' => ['Automatic compaction', 'Summarize older context as the conversation grows.'],
        'compaction.model' => ['Compaction model', 'Choose which model summarizes older context.'],
        'compaction.thinking_level' => ['Compaction reasoning', 'Choose how much reasoning the summarizer uses.'],
    ];

    private bool $draft = false;

    public function __construct(
        private readonly SetupSettingsService $settings,
        private readonly ThemeRegistry $themes,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function hasDraft(): bool
    {
        return $this->draft;
    }

    public function mount(Tui $tui, ContainerWidget $pane, SettingsLayerEnum $layer, ProvidersSetupFlowInterface $providers, \Closure $back, \Closure $heading): void
    {
        $show = static function (string $text, array $items, \Closure $pick, \Closure $cancel) use ($tui, $pane, $heading): void {
            $descriptions = array_column($items, 'description', 'value');
            $list = new SelectListWidget(array_map(static fn (array $item): array => ['value' => $item['value'], 'label' => $item['label']], $items), maxVisible: 9);
            $list->onSelect(static fn (SelectEvent $event) => $pick($event->getValue()));
            $list->onCancel($cancel);
            [$title, $details] = array_pad(explode("\n", $text, 2), 2, '');
            $explanation = new TextWidget($descriptions[$list->getSelectedItem()['value'] ?? ''] ?? $details);
            $list->onSelectionChange(static function (SelectionChangeEvent $event) use ($explanation, $descriptions, $details): void {
                $explanation->setText($descriptions[$event->getValue()] ?? $details);
            });
            $pane->clear()->add($heading($title))->add($explanation)->add($list);
            $tui->setFocus($list);
        };
        $menu = function () use ($show, $layer, $providers, $back, &$menu): void {
            $show("Preferences\nEdit common preferences here. For the complete inventory, use /settings-show.", self::items(array_keys(self::GROUPS)), function (string $group) use ($show, $layer, $providers, &$menu): void {
                $section = function () use ($show, $group, $layer, $providers, &$menu, &$section): void {
                    $snapshot = $this->settings->load();
                    $items = [];
                    foreach (self::GROUPS[$group] as $path) {
                        [$label, $description] = self::HELP[$path];
                        $value = self::display($this->settings->resolver->resolve($snapshot, $path));
                        $items[] = ['value' => $path, 'label' => $label.' · '.$value, 'description' => $description];
                    }
                    $show($group."\nChoose a preference to edit.", $items, fn (string $key) => $this->edit($show, $layer, $key, $providers, $section), $menu);
                };
                $section();
            }, $back);
        };
        $menu();
    }

    private function edit(\Closure $show, SettingsLayerEnum $layer, string $key, ProvidersSetupFlowInterface $providers, \Closure $back): void
    {
        $this->draft = false;
        [$label, $help] = self::HELP[$key];
        $snapshot = $this->settings->load();
        $resolver = $this->settings->resolver;
        $local = SettingsLayerEnum::User === $layer ? $snapshot->userRaw : $snapshot->projectRaw;
        $localValue = $resolver->resolve(new SettingsResolutionDTO([], [], [], $local), $key);
        $effective = $resolver->resolve($snapshot, $key);
        $reset = $resolver->resolve($this->settings->withoutOverride($snapshot, $layer, $key), $key);
        $details = $label."\n".$help."\nEffective: ".self::display($effective).' ('.($effective->layer->value ?? 'no source').')';
        $details .= "\nSelected scope: ".($localValue->exists ? self::display($localValue) : 'inherited');
        if (SettingsLayerEnum::User === $layer && SettingsLayerEnum::Project === $effective->layer) {
            $details .= ' · overridden by project';
        }
        $choices = match ($key) {
            'tui.theme' => $this->themes->getNames(),
            'compaction.model' => $providers->configuredModelRefs(),
            'compaction.thinking_level' => ModelResolver::LEVELS,
            default => ['true', 'false'],
        };
        $items = self::items($choices);
        if (\in_array($key, ['compaction.model', 'compaction.thinking_level'], true)) {
            $items[] = ['value' => ':session', 'label' => 'Use session default (explicit null)'];
        }
        if ($localValue->exists) {
            $items[] = ['value' => ':reset', 'label' => 'Reset override → '.self::display($reset)];
        }
        $show($details, $items, function (string $option) use ($show, $layer, $key, $label, $back, $providers): void {
            $this->draft = true;
            $retry = fn () => $this->edit($show, $layer, $key, $providers, $back);
            $draft = match ($option) {
                ':session', ':reset' => null,
                'true' => true,
                'false' => false,
                default => $option,
            };
            $show($label."\nPending: ".(':reset' === $option ? 'remove override' : $option), self::items(['Save', 'Discard']), function (string $answer) use ($show, $layer, $key, $option, $draft, $retry): void {
                if ('Save' !== $answer) {
                    $retry();

                    return;
                }
                try {
                    $saved = $this->settings->save($layer, ':reset' === $option ? [] : [$key => $draft], ':reset' === $option ? [$key] : []);
                    $message = $saved ? 'Saved. Changes apply next session.' : 'No changes. File was not written.';
                } catch (\Throwable $error) {
                    $this->logger->notice('settings.setup.refused', ['component' => 'setup', 'event_type' => 'settings.setup.refused', 'exception_class' => $error::class]);
                    $message = 'Not saved. Check the setting and target YAML.';
                }
                $this->draft = false;
                $show($message."\nPress Enter to continue.", self::items(['Continue']), $retry, $retry);
            }, $retry);
        }, $back);
    }

    private static function display(SettingsValueDTO $value): string
    {
        return match (true) {
            !$value->exists => '(unset)',
            null === $value->value => '(session default)',
            \is_bool($value->value) => $value->value ? 'On' : 'Off',
            \is_string($value->value) => $value->value,
            default => '(not displayed)',
        };
    }

    /** @param list<string> $values
     * @return list<array{value: string, label: string}>
     */
    private static function items(array $values): array
    {
        return array_map(static fn (string $value): array => ['value' => $value, 'label' => match ($value) {
            'true' => 'On', 'false' => 'Off', default => $value,
        }], $values);
    }
}
