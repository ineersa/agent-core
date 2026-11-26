<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\CodingAgent\CLI\Setup\DefinitionSetupService;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\MultiSelectEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Yaml\Yaml;

final class DefinitionSetupScreen
{
    private Tui $tui;
    private ContainerWidget $panel;
    private TextWidget $heading;
    private TextWidget $metadata;
    private TextWidget $warning;
    private TextWidget $notice;
    private \Closure $exit;

    public function __construct(
        private readonly DefinitionSetupService $service,
        private readonly SettingsLayerEnum $scope,
        private readonly LoggerInterface $logger,
        private readonly TuiTheme $theme,
        private readonly bool $skill,
    ) {
    }

    public function mount(Tui $tui, ContainerWidget $pane, \Closure $exit): void
    {
        $this->tui = $tui;
        $this->exit = $exit;
        $this->heading = (new TextWidget(''))->setStyle(new Style(bold: true));
        $this->metadata = new TextWidget('');
        $this->warning = (new TextWidget(''))->setStyle(new Style(bold: true));
        $this->notice = new TextWidget('');
        $this->panel = new ContainerWidget();
        $pane->add($this->heading)->add($this->metadata)->add($this->warning)->add($this->notice)->add($this->panel);
        $this->menu();
    }

    private function menu(): void
    {
        $this->heading->setText($this->theme->color(ThemeColorEnum::Accent, $this->skill ? 'Manage skills' : 'Manage agents'));
        $this->metadata->setText($this->skill ? $this->theme->muted('Built-in skills appear after normal skill discovery.') : '');
        $this->warning->setText('');
        $entries = $this->service->entries($this->skill, $this->scope);
        $items = [];
        foreach ($entries as $path => $entry) {
            $items[] = ['value' => $path, 'label' => $this->theme->color(ThemeColorEnum::Accent, StringUtils::stripControlBytes($entry['name'])).$this->theme->muted(' · '.$entry['owner'].($entry['builtIn'] ? ' · built-in' : '').($entry['editable'] ? '' : ' · read-only').(null === $entry['shadowedBy'] ? '' : ' · shadowed')).' · '.StringUtils::stripControlBytes($path)];
        }
        $this->choose([] === $items ? [['value' => '', 'label' => 'No definitions found']] : $items, function (string $path) use ($entries): void {
            if (isset($entries[$path])) {
                $this->details($path, $entries[$path]);
            }
        }, $this->exit);
    }

    /** @param array{name: string, owner: string, editable: bool, builtIn: bool, shadowedBy: ?string, reveals: ?string} $entry */
    private function details(string $path, array $entry): void
    {
        $this->heading->setText($this->theme->color(ThemeColorEnum::Accent, StringUtils::stripControlBytes($entry['name'])));
        $message = 'Source: '.StringUtils::stripControlBytes($path).' · Owner: '.$entry['owner'].($entry['builtIn'] ? ' · Built-in' : '');
        if (null !== $entry['shadowedBy']) {
            $message .= "\nShadowed by: ".StringUtils::stripControlBytes($entry['shadowedBy']);
        }
        if (!$entry['editable']) {
            $message .= \in_array($entry['owner'], ['user', 'project'], true) ? ' · Switch to the owning scope (F2) to edit.' : ' · Read-only.';
        }
        $this->metadata->setText($this->theme->muted($message));
        $this->warning->setText($entry['builtIn'] ? $this->theme->warning('Built-in: edits can be overwritten on refresh or rematerialization. Deletion is disabled.') : '');
        $this->notice->setText('');
        $items = [];
        if ($entry['editable']) {
            $items[] = ['value' => 'edit', 'label' => $this->theme->color(ThemeColorEnum::Accent, 'Edit frontmatter')];
            if (!$entry['builtIn']) {
                $items[] = ['value' => 'delete', 'label' => $this->theme->error('Delete definition')];
            }
        }
        $items[] = ['value' => 'back', 'label' => $this->theme->muted('Back to definitions')];
        $back = function (): void {
            $this->notice->setText('');
            $this->menu();
        };
        $this->choose($items, function (string $action) use ($path, $entry, $back): void {
            if ('back' === $action) {
                $back();

                return;
            }
            $raw = $this->service->read($path);
            $return = fn () => $this->details($path, $entry);
            if ('edit' === $action) {
                $this->form($path, $raw, $return);

                return;
            }
            $this->warning->setText($this->theme->error(StringUtils::stripControlBytes('Delete '.($this->skill ? \dirname($path).' (entire skill directory and assets)' : $path).'?'.(null === $entry['reveals'] ? '' : ' This reveals '.$entry['reveals'].'.'))));
            $this->choose([['value' => 'cancel', 'label' => 'Cancel'], ['value' => 'confirm', 'label' => $this->theme->error('Confirm deletion')]], function (string $choice) use ($path, $raw, $return): void {
                if ('confirm' !== $choice) {
                    $return();

                    return;
                }
                $this->service->delete($this->skill, $this->scope, $path, $raw);
                $this->notice->setText($this->theme->success('Deleted '.StringUtils::stripControlBytes($path)));
                $this->menu();
            }, $return);
        }, $back);
    }

    private function form(string $path, string $raw, \Closure $back): void
    {
        $items = [];
        $fields = $this->service->fields($raw);
        foreach ($this->service->supportedFields($this->skill) as $key => $type) {
            $current = $fields[$key] ?? null;
            $text = $this->fieldText($current);
            $label = ucfirst(strtolower(str_replace('-', ' ', (string) preg_replace('/(?<!^)[A-Z]/', ' $0', $key))));
            $submenu = match (true) {
                'extensions' === $key => fn (string $_, callable $done): SelectListWidget => $this->extensionPicker($current, $done),
                'bool' === $type || \in_array($key, ['model', 'thinking', 'systemPromptMode'], true) => fn (): SelectListWidget => $this->picker($key, $text, $this->service->choices($key, $type, $fields)),
                default => static fn (string $value): SettingsTextInputWidget => new SettingsTextInputWidget($value, cancelUnchanged: true),
            };
            $help = $this->service->fieldHelp($this->skill, $key);
            $items[] = new SettingItem($key, $label, $text, $help, submenu: $submenu);
        }
        $list = new SettingsListWidget($items, maxVisible: 14);
        $list->onCancel($back);
        $list->onChange(function (SettingChangeEvent $event) use ($list, $fields, $path, $raw, $back): void {
            if ($event->getValue() === $this->fieldText($fields[$event->getId()] ?? null)) {
                return;
            }
            $this->attempt(function () use ($event, $path, $raw, $back): void {
                $saved = $this->service->save($this->skill, $this->scope, $path, $raw, $event->getId(), $event->getValue());
                $this->form($path, $saved, $back);
                $this->notice->setText($this->theme->success('Saved frontmatter to '.StringUtils::stripControlBytes($path)));
            }, function () use ($list, $event, $fields): void {
                $old = $fields[$event->getId()] ?? null;
                $list->updateValue($event->getId(), $this->fieldText($old));
            });
        });
        $this->show($list);
    }

    private function fieldText(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_string($value) => $value,
            default => trim(Yaml::dump($value, 0)),
        };
    }

    /** @param list<string> $choices */
    private function picker(string $key, string $current, array $choices): SelectListWidget
    {
        $items = [];
        $unset = match ($key) {
            'model', 'thinking' => 'Inherit parent / default',
            'systemPromptMode' => 'Default (replace)',
            'inheritProjectContext' => 'Default (true)',
            default => 'Default (false)',
        };
        foreach ($choices as $value) {
            $items[] = ['value' => $value, 'label' => '' === $value ? $unset : $value];
        }
        $index = array_search($current, $choices, true);
        if (false === $index) {
            $index = \count($items);
            $items[] = ['value' => $current, 'label' => StringUtils::stripControlBytes($current).' (current; unavailable)'];
        }

        return (new SelectListWidget($items, maxVisible: 10))->setSelectedIndex($index);
    }

    private function extensionPicker(mixed $current, callable $done): SelectListWidget
    {
        $selected = \is_array($current) ? $current : [];
        $available = $this->service->availableExtensions();
        $items = [];
        foreach (array_unique([...$selected, ...$available]) as $class) {
            $items[] = ['value' => $class, 'label' => StringUtils::stripControlBytes($class).(\in_array($class, $available, true) ? '' : ' (current; unavailable)'), 'checked' => \in_array($class, $selected, true)];
        }
        if ([] === $items) {
            return new SelectListWidget([['value' => '', 'label' => 'No optional extensions available']]);
        }
        $list = new SelectListWidget($items, maxVisible: 10, multiselect: true);
        $list->on(MultiSelectEvent::class, static function (MultiSelectEvent $event) use ($done, $current): void {
            $values = $event->getValues();
            $done([] === $values && [] !== $current ? '' : trim(Yaml::dump($values, 0)));
        });

        return $list;
    }

    /** @param list<array{value: string, label: string}> $items */
    private function choose(array $items, \Closure $select, \Closure $cancel): void
    {
        $list = new SelectListWidget($items, maxVisible: 12);
        $list->onSelect(fn (SelectEvent $event) => $this->attempt(static fn () => $select($event->getValue())));
        $list->onCancel($cancel);
        $this->show($list);
    }

    private function show(SelectListWidget|SettingsListWidget $list): void
    {
        $this->panel->clear()->add($list);
        $this->tui->setFocus($list);
    }

    private function attempt(\Closure $action, ?\Closure $restore = null): void
    {
        try {
            $action();
        } catch (\Throwable $error) {
            $this->logger->notice('definition.setup.refused', ['component' => 'definition_setup', 'event_type' => 'definition.setup.refused', 'exception_class' => $error::class]);
            $this->notice->setText($this->theme->error('Not saved: '.StringUtils::stripControlBytes($error->getMessage())));
            $restore?->__invoke();
        }
    }
}
