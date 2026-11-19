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
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final readonly class CoreSettingsScreen
{
    private const GROUPS = [
        'tui' => ['tui.theme', 'tui.transcript.previews.expanded_by_default'],
        'forks' => ['forks.enabled'],
        'compaction' => ['compaction.auto_enabled', 'compaction.model', 'compaction.thinking_level'],
    ];

    public function __construct(
        private SetupSettingsService $settings,
        private ThemeRegistry $themes,
        private LoggerInterface $logger,
    ) {
    }

    public function mount(Tui $tui, ContainerWidget $pane, SettingsLayerEnum $layer, ProvidersSetupFlowInterface $providers, \Closure $back): void
    {
        $show = static function (string $text, array $items, \Closure $pick, \Closure $cancel) use ($tui, $pane): void {
            $list = new SelectListWidget($items, maxVisible: 9);
            $list->onSelect(static fn (SelectEvent $event) => $pick($event->getValue()));
            $list->onCancel($cancel);
            $pane->clear()->add(new TextWidget($text))->add($list);
            $tui->setFocus($list);
        };
        $menu = function () use ($show, $layer, $providers, $back, &$menu): void {
            $snapshot = $this->settings->load();
            $help = $this->settings->descriptions($snapshot);
            $groups = array_values(array_diff(array_keys($snapshot->defaultsRaw), ['ai', 'extensions']));
            $show('Choose a settings group', self::items($groups), function (string $group) use ($show, $help, $layer, $providers, &$menu): void {
                $paths = self::GROUPS[$group] ?? [];
                foreach ($help as $path => $description) {
                    if (str_starts_with($path, $group.'.') && !\in_array($path, $paths, true)) {
                        $paths[] = $path;
                    }
                }
                $show($help[$group] ?? $group, self::items([...$paths, 'Back']), function (string $key) use ($show, $group, $help, $layer, $providers, &$menu): void {
                    if ('Back' === $key) {
                        $menu();
                    } elseif (\in_array($key, self::GROUPS[$group] ?? [], true)) {
                        $this->edit($show, $layer, $key, $help[$key] ?? '', $providers, $menu);
                    } else {
                        $show($key." · Read only\n".($help[$key] ?? ''), self::items(['Back']), $menu, $menu);
                    }
                }, $menu);
            }, $back);
        };
        $menu();
    }

    private function edit(\Closure $show, SettingsLayerEnum $layer, string $key, string $help, ProvidersSetupFlowInterface $providers, \Closure $back): void
    {
        $snapshot = $this->settings->load();
        $resolver = $this->settings->resolver;
        $local = SettingsLayerEnum::User === $layer ? $snapshot->userRaw : $snapshot->projectRaw;
        $localValue = $resolver->resolve(new SettingsResolutionDTO([], [], [], $local), $key);
        $effective = $resolver->resolve($snapshot, $key);
        $reset = $resolver->resolve($this->settings->withoutOverride($snapshot, $layer, $key), $key);
        $details = $key."\n".$help."\nEffective: ".self::display($effective).' ('.($effective->layer->value ?? 'no source').')';
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
        $items[] = ['value' => ':back', 'label' => 'Back'];
        $show($details, $items, function (string $option) use ($show, $layer, $key, $back, $help, $providers): void {
            if (':back' === $option) {
                $back();

                return;
            }
            $retry = fn () => $this->edit($show, $layer, $key, $help, $providers, $back);
            $draft = match ($option) {
                ':session', ':reset' => null,
                'true' => true,
                'false' => false,
                default => $option,
            };
            $show('Pending: '.$key.' → '.(':reset' === $option ? 'remove override' : $option), self::items(['Save', 'Discard']), function (string $answer) use ($show, $layer, $key, $option, $draft, $retry): void {
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
                $show($message, self::items(['Back']), $retry, $retry);
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
        return array_map(static fn (string $value): array => ['value' => $value, 'label' => $value], $values);
    }
}
