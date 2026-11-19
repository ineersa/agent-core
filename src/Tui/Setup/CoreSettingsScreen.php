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
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Yaml\Yaml;

final class CoreSettingsScreen
{
    private bool $draft = false;

    public function __construct(
        private readonly SetupSettingsService $settings,
        private readonly LoggerInterface $logger,
        private readonly ThemeRegistry $themes,
    ) {
    }

    public function hasDraft(): bool
    {
        return $this->draft;
    }

    public function mount(Tui $tui, ContainerWidget $pane, SettingsLayerEnum $layer, ProvidersSetupFlowInterface $providers, \Closure $back, \Closure $heading): void
    {
        $groups = $this->settings->inventory();
        $menu = function () use ($tui, $pane, $layer, $groups, $back, $heading, $providers, &$menu): void {
            $items = [];
            foreach ($groups as $group => $fields) {
                $items[] = ['value' => $group, 'label' => self::label($group), 'description' => \count($fields).' settings'];
            }
            $list = new SelectListWidget($items, maxVisible: 10);
            $list->onSelect(fn (SelectEvent $event) => $this->section($tui, $pane, $layer, $event->getValue(), $groups[$event->getValue()], $menu, $heading, $providers));
            $list->onCancel($back);
            $pane->clear()->add($heading('Preferences'))->add($list);
            $tui->setFocus($list);
        };
        $menu();
    }

    /** @param array<string, array{sample: mixed, help: string}> $fields */
    private function section(Tui $tui, ContainerWidget $pane, SettingsLayerEnum $layer, string $group, array $fields, \Closure $menu, \Closure $heading, ProvidersSetupFlowInterface $providers, string $message = ''): void
    {
        $this->draft = false;
        $snapshot = $this->settings->load();
        $local = SettingsLayerEnum::User === $layer ? $snapshot->userRaw : $snapshot->projectRaw;
        $items = [];
        $resets = [];
        /** @var array<string, mixed> $values */
        $values = [];
        foreach ($fields as $key => ['sample' => $sample, 'help' => $help]) {
            $effective = $this->settings->resolver->resolve($snapshot, $key);
            $override = $this->settings->resolver->resolve(new SettingsResolutionDTO([], [], [], $local), $key);
            $label = self::label(str_replace($group.'.', '', $key));
            $source = $effective->composite ? 'merged layers' : ($effective->layer->value ?? 'unset');
            $details = $help.' Effective source: '.$source.'.'.($override->exists ? ' Override in this scope.' : ' Inherited in this scope.');
            $secret = self::masked($key);
            $initial = '';
            if (!$secret && $override->exists) {
                $initial = self::encode($override->value, $sample);
            } elseif (!$secret && !\is_array($sample) && $effective->exists) {
                $initial = self::encode($effective->value, $sample);
            }
            $details .= \is_array($sample) ? ' Enter a one-line YAML collection.' : '';
            if ($secret) {
                $details .= ' Input is hidden. Entries update this scope’s map; Reset removes its override.';
            }
            $choices = match ($key) {
                'tui.theme' => $this->themes->getNames(),
                'ai.default_model', 'compaction.model', 'forks.model' => ['Inherit', ...$providers->configuredModelRefs()],
                'ai.default_reasoning', 'compaction.thinking_level', 'forks.thinking_level' => ['Inherit', ...ModelResolver::LEVELS],
                default => [],
            };
            $submenu = null;
            if ([] !== $choices) {
                $submenu = static function () use ($choices, $key, $effective, &$values): SelectListWidget {
                    $list = new SelectListWidget(array_map(static fn (string $choice): array => ['value' => $choice, 'label' => $choice], $choices));
                    $current = \array_key_exists($key, $values) ? $values[$key] : $effective->value;
                    $index = array_search($current ?? 'Inherit', $choices, true);
                    if (false !== $index) {
                        $list->setSelectedIndex($index);
                    }

                    return $list;
                };
            } elseif (!\is_bool($sample)) {
                $submenu = static function () use ($initial, $secret, $sample, $key, &$values): SettingsTextInputWidget {
                    $text = !$secret && \array_key_exists($key, $values) ? self::encode($values[$key], $sample) : $initial;

                    return new SettingsTextInputWidget($text, secret: $secret);
                };
            }
            $items[] = new SettingItem($key, $label, self::display($effective, $secret), trim($details),
                values: \is_bool($sample) ? ['Off', 'On'] : [],
                submenu: $submenu,
            );
            if ($override->exists) {
                $afterReset = $this->settings->withoutOverride($snapshot, $layer, $key);
                $revealed = $this->settings->resolver->resolve($afterReset, $key);
                $resets[] = ['value' => $key, 'label' => $label, 'description' => 'After reset: '.self::display($revealed, $secret)];
            }
        }
        if ([] !== $resets) {
            $items[] = new SettingItem(':reset', 'Reset an override', '', 'Remove one setting from this scope without copying defaults.', submenu: static fn (): SelectListWidget => new SelectListWidget($resets));
        }
        $items[] = new SettingItem(':save', 'Save changes', '', values: ['Save']);
        $items[] = new SettingItem(':discard', 'Discard changes', '', values: ['Discard']);
        $widget = new SettingsListWidget($items, maxVisible: 12);
        $status = new TextWidget($message);
        $remove = [];
        $invalid = [];
        $reload = fn (string $message = '') => $this->section($tui, $pane, $layer, $group, $fields, $menu, $heading, $providers, $message);
        $widget->onChange(function (SettingChangeEvent $event) use ($widget, $fields, $status, $layer, $reload, &$values, &$remove, &$invalid): void {
            $id = $event->getId();
            $value = $event->getValue();
            try {
                if (':discard' === $id) {
                    $reload('Draft discarded.');

                    return;
                }
                if (':save' === $id) {
                    $widget->updateValue($id, '');
                    if ([] !== $invalid) {
                        $status->setText('Correct invalid fields or discard the draft before saving.');

                        return;
                    }
                    $saved = $this->settings->save($layer, $values, array_keys($remove));
                    $reload($saved ? 'Saved. Changes apply next session.' : 'No changes. File was not written.');

                    return;
                }
                if (':reset' === $id) {
                    unset($values[$value], $invalid[$value]);
                    $remove[$value] = true;
                    $widget->updateValue($id, '');
                    $widget->updateValue($value, 'Reset pending');
                } else {
                    // The vendor puts the raw submitted value on the row before emitting this event.
                    $widget->updateValue($id, 'Invalid value');
                    $invalid[$id] = true;
                    $this->draft = true;
                    $decoded = 'Inherit' === $value && null === $fields[$id]['sample'] ? null : self::decode($value, $fields[$id]['sample']);
                    if (self::masked($id) && [] !== $decoded && array_is_list($decoded)) {
                        throw new \InvalidArgumentException('Expected a mapping.');
                    }
                    $values[$id] = $decoded;
                    $widget->updateValue($id, self::display(new SettingsValueDTO(exists: true, value: $decoded), self::masked($id)));
                    unset($remove[$id], $invalid[$id]);
                }
                $this->draft = true;
                $status->setText('Unsaved changes · Save or Discard');
            } catch (\Throwable $error) {
                $this->logger->notice('settings.setup.refused', ['component' => 'setup', 'event_type' => 'settings.setup.refused', 'exception_class' => $error::class]);
                $status->setText('Not saved. Check the value and target settings, or discard this draft.');
            }
        });
        $widget->onCancel(function () use ($status, $menu): void {
            if ($this->draft) {
                $status->setText('Save or discard your changes before leaving.');
            } else {
                $menu();
            }
        });
        $pane->clear()->add($heading(self::label($group)))->add($status)->add($widget);
        $tui->setFocus($widget);
    }

    private static function masked(string $key): bool
    {
        return \in_array($key, ['ai.providers', 'extensions.settings', 'compaction.provider_overrides', 'compaction.model_overrides'], true);
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

    private static function display(SettingsValueDTO $value, bool $secret): string
    {
        return match (true) {
            !$value->exists => 'Unset',
            $secret => [] === $value->value ? 'Empty' : 'Configured · hidden',
            null === $value->value => 'Default',
            \is_bool($value->value) => $value->value ? 'On' : 'Off',
            \is_array($value->value) => \count($value->value).' entries',
            \is_scalar($value->value) => StringUtils::stripControlBytes((string) $value->value),
            default => 'Unsupported value',
        };
    }
}
