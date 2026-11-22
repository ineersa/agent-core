<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\LlmRequestRetryPolicy;
use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupFlowInterface;
use Ineersa\CodingAgent\Config\Ai\AiHttpConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsResolutionDTO;
use Ineersa\CodingAgent\Config\SettingsValueDTO;
use Ineersa\CodingAgent\Config\SetupSettingsService;
use Ineersa\Hatfield\ExtensionApi\Agent\ThinkingLevelEnum;
use Ineersa\Tui\Theme\ThemeRegistry;
use Ineersa\Tui\Theme\TuiTheme;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Yaml\Yaml;

final class CoreSettingsScreen
{
    private const HTTP = [
        'ai.http.timeout' => [AiHttpConfig::DEFAULT_TIMEOUT, 'seconds'],
        'ai.http.max_duration' => [AiHttpConfig::DEFAULT_MAX_DURATION, 'seconds'],
        'ai.http.max_retries' => [LlmRequestRetryPolicy::DEFAULT_MAX_RETRIES, 'retries'],
        'ai.http.base_delay_ms' => [LlmRequestRetryPolicy::DEFAULT_BASE_DELAY_MS, 'milliseconds'],
        'ai.http.max_delay_ms' => [LlmRequestRetryPolicy::DEFAULT_MAX_DELAY_MS, 'milliseconds'],
    ];

    public function __construct(
        private readonly SetupSettingsService $settings,
        private readonly LoggerInterface $logger,
        private readonly ThemeRegistry $themes,
    ) {
    }

    public function mount(Tui $tui, ContainerWidget $pane, SettingsLayerEnum $layer, ProvidersSetupFlowInterface $providers, \Closure $back, \Closure $heading, TuiTheme $theme, \Closure $removeHint): void
    {
        $groups = $this->settings->inventory();
        $menu = function () use ($tui, $pane, $layer, $groups, $back, $heading, $providers, $theme, $removeHint, &$menu): void {
            $items = [];
            foreach ($groups as $group => $fields) {
                $items[] = ['value' => $group, 'label' => self::label($group), 'description' => \count($fields).' settings'];
            }
            $list = new SelectListWidget($items, maxVisible: 10);
            $list->onSelect(fn (SelectEvent $event) => $this->section($tui, $pane, $layer, $event->getValue(), $groups[$event->getValue()], $menu, $heading, $providers, $theme, $removeHint));
            $list->onCancel($back);
            $pane->clear()->add($heading('Preferences'))->add($list);
            $tui->setFocus($list);
        };
        $menu();
    }

    /** @param array<string, array{sample: mixed, help: string}> $fields */
    private function section(Tui $tui, ContainerWidget $pane, SettingsLayerEnum $layer, string $group, array $fields, \Closure $menu, \Closure $heading, ProvidersSetupFlowInterface $providers, TuiTheme $theme, \Closure $removeHint): void
    {
        $snapshot = $this->settings->load();
        $items = [];
        $local = [];
        $status = new TextWidget('');
        $context = new TextWidget('');
        foreach ($fields as $key => ['sample' => $sample, 'help' => $help]) {
            $effective = $this->settings->resolver->resolve($snapshot, $key);
            $local[$key] = $this->localValue($snapshot, $layer, $key)->exists;
            $label = self::label(str_replace($group.'.', '', $key));
            $edit = $this->settings->editValue($snapshot, $layer, $key);
            $choices = fn (): array => match ($key) {
                'tui.theme' => $this->themes->getNames(),
                'ai.default_model', 'compaction.model', 'forks.model' => [self::inherit($key), ...$providers->configuredModelRefs()],
                'ai.default_reasoning', 'compaction.thinking_level', 'forks.thinking_level' => [self::inherit($key), ...ThinkingLevelEnum::values()],
                default => [],
            };
            $submenu = \is_bool($sample) ? null : function () use ($label, $help, $context, $choices, $key, $sample, $layer, $removeHint, $tui): SelectListWidget {
                $removeHint(false);
                $context->setText($label."\n".$help.(isset(self::HTTP[$key]) ? ' Unit: '.self::HTTP[$key][1].'.' : '')."\nEnter applies · Esc cancels");
                $options = $choices();
                $edit = $this->settings->editValue($this->settings->load(), $layer, $key);
                if ([] !== $options) {
                    $editor = new SelectListWidget(array_map(static fn (string $choice): array => ['value' => $choice, 'label' => $choice], $options));
                    $index = array_search(self::display($key, $edit), $options, true);
                    if (false !== $index) {
                        $editor->setSelectedIndex($index);
                    }
                } else {
                    $value = $edit->value ?? (self::HTTP[$key][0] ?? null);
                    $secret = self::masked($key);
                    $initial = $secret || (\is_array($sample) && !$this->localValue($this->settings->load(), $layer, $key)->exists) ? '' : self::encode($value, $sample);
                    $editor = new SettingsTextInputWidget($initial, secret: $secret, cancelUnchanged: true);
                    if (\is_array($sample)) {
                        $context->setText($context->getText().' · One-line YAML'.($secret ? ' (hidden patch)' : ''));
                    }
                }
                $editor->onCancel(static function () use ($context, $removeHint, $tui): void {
                    $context->setText('');
                    $focused = $tui->getFocus();
                    $removeHint($focused instanceof ScopedSettingsListWidget && $focused->canRemove());
                });

                return $editor;
            };
            $items[] = new SettingItem($key, $label, self::display($key, \is_bool($sample) ? $edit : $effective),
                $help.(\is_bool($sample) && SettingsLayerEnum::User === $layer && SettingsLayerEnum::Project === $effective->layer ? ' Project override masks this value.' : ''),
                values: \is_bool($sample) ? ['Off', 'On'] : [], submenu: $submenu);
        }
        $widget = new ScopedSettingsListWidget($items, $theme);
        foreach ($local as $key => $exists) {
            $widget->setLocal($key, $exists);
        }
        $persist = function (string $key, ?string $input) use ($widget, $fields, $status, $context, $layer, $removeHint, $tui, &$snapshot): void {
            $context->setText('');
            try {
                $values = [];
                if (null !== $input) {
                    $value = $input === self::inherit($key) && null === $fields[$key]['sample'] ? null : self::decode($input, $fields[$key]['sample']);
                    if (self::masked($key) && [] !== $value && array_is_list($value)) {
                        throw new \InvalidArgumentException('Expected a mapping.');
                    }
                    $values[$key] = $value;
                }
                $saved = $this->settings->save($layer, $values, null === $input ? [$key] : []);
                $snapshot = $this->settings->load();
                $effective = $this->settings->resolver->resolve($snapshot, $key);
                $status->setText(($saved ? 'Saved. ' : 'No changes. ').match (true) {
                    SettingsLayerEnum::User === $layer && SettingsLayerEnum::Project === $effective->layer => 'Project override is still effective.',
                    default => 'Effective source: '.($effective->layer->value ?? 'merged layers').'.',
                });
            } catch (\Throwable $error) {
                $this->logger->notice('settings.setup.refused', ['component' => 'setup', 'event_type' => 'settings.setup.refused', 'exception_class' => $error::class]);
                $status->setText('Not saved. Check the value and target settings.');
            }
            $widget->setLocal($key, $this->localValue($snapshot, $layer, $key)->exists);
            $removeHint($tui->getFocus() === $widget && $widget->canRemove());
            $display = \is_bool($fields[$key]['sample']) ? $this->settings->editValue($snapshot, $layer, $key) : $this->settings->resolver->resolve($snapshot, $key);
            $widget->updateValue($key, self::display($key, $display));
        };
        $widget->onChange(static fn (SettingChangeEvent $event) => $persist($event->getId(), $event->getValue()));
        $widget->onCancel($menu);
        $removal = new ContainerWidget();
        $keys = new Keybindings(['remove_override' => ['d']]);
        $widget->onInput(function (string $input) use ($keys, $widget, $fields, $layer, $status, $removal, $tui, $persist, &$snapshot): bool {
            if ([] !== $widget->all() || !$keys->matches($input, 'remove_override')) {
                return false;
            }
            // SettingsListWidget has no public selected-row accessor; use a native chooser.
            $options = [];
            foreach ($fields as $key => $_) {
                if ($this->localValue($snapshot, $layer, $key)->exists) {
                    $after = $this->settings->withoutOverride($snapshot, $layer, $key);
                    $options[] = ['value' => $key, 'label' => self::label($key), 'description' => 'Reveals '.self::display($key, $this->settings->resolver->resolve($after, $key))];
                }
            }
            if ([] === $options) {
                $status->setText('No overrides in this section and scope.');

                return true;
            }
            $list = new SelectListWidget($options);
            $close = static function () use ($tui, $widget, $removal): void {
                $removal->clear();
                $tui->setFocus($widget);
            };
            $list->onSelect(static function (SelectEvent $event) use ($persist, $close): void {
                $persist($event->getValue(), null);
                $close();
            });
            $list->onCancel($close);
            $removal->add(new TextWidget('Remove from this scope · Enter confirms · Esc cancels'))->add($list);
            $tui->setFocus($list);

            return true;
        });
        $pane->clear()->add($heading(self::label($group)))->add(new TextWidget($theme->success('●').' Set here  '.$theme->error('○').' Not set here'))->add($status)->add($context)->add($removal)->add($widget);
        $tui->setFocus($widget);
    }

    private function localValue(SettingsResolutionDTO $snapshot, SettingsLayerEnum $layer, string $key): SettingsValueDTO
    {
        $local = SettingsLayerEnum::User === $layer ? $snapshot->userRaw : $snapshot->projectRaw;

        return $this->settings->resolver->resolve(new SettingsResolutionDTO([], [], [], $local), $key);
    }

    private static function inherit(string $key): string
    {
        return match ($key) {
            'forks.model', 'forks.thinking_level' => 'Inherit from parent',
            'compaction.model', 'compaction.thinking_level' => 'Use session setting',
            'ai.default_model' => 'First enabled model',
            'ai.default_reasoning' => 'Use model default',
            default => 'Default',
        };
    }

    private static function masked(string $key): bool
    {
        return \in_array($key, ['compaction.provider_overrides', 'compaction.model_overrides'], true);
    }

    private static function label(string $key): string
    {
        return ucfirst(str_replace(['_', '.'], [' ', ' / '], $key));
    }

    private static function encode(mixed $value, mixed $sample): string
    {
        if (\is_string($sample)) {
            return \is_string($value) ? StringUtils::stripControlBytes($value) : '';
        }

        return StringUtils::stripControlBytes(trim(Yaml::dump($value, 0)));
    }

    private static function decode(string $value, mixed $sample): mixed
    {
        if (\is_bool($sample)) {
            return 'On' === $value;
        }
        $decoded = \is_string($sample) ? $value : Yaml::parse($value);
        if ((\is_array($sample) && !\is_array($decoded)) || (\is_int($sample) && !\is_int($decoded))) {
            throw new \InvalidArgumentException('Wrong value type.');
        }

        return $decoded;
    }

    private static function display(string $key, SettingsValueDTO $value): string
    {
        if (isset(self::HTTP[$key])) {
            [$default, $unit] = self::HTTP[$key];

            return (\is_int($value->value) ? $value->value : $default).' '.$unit.(null === $value->value ? ' (default)' : '');
        }

        return match (true) {
            !$value->exists => 'Unset',
            self::masked($key) => [] === $value->value ? 'Empty' : 'Configured · hidden',
            null === $value->value => self::inherit($key),
            \is_bool($value->value) => $value->value ? 'On' : 'Off',
            \is_array($value->value) => \count($value->value).' entries',
            \is_scalar($value->value) => StringUtils::stripControlBytes((string) $value->value),
            default => 'Unsupported value',
        };
    }
}
