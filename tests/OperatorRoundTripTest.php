<?php

use craft\elements\Entry;
use craft\fields\Checkboxes;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch;
use craft\fields\Number;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\Field as FieldFactory;
use markhuot\craftpest\factories\Section as SectionFactory;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetStatus;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\helpers\Values;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;

/**
 * Creates a section with one field of the given type and a single entry, then previews, applies and undoes
 * one operation. Returns [before, afterApply, afterUndo] as serialized values.
 */
function roundTrip(string $fieldType, array $fieldConfig, mixed $initial, string $operator, string $operation, array $options): array
{
    $field = FieldFactory::factory()->type($fieldType)->set($fieldConfig)->create();
    $section = SectionFactory::factory()->fields($field)->create();
    $entry = EntryFactory::factory()->section($section->handle)->set($field->handle, $initial)->create();

    $read = function() use ($entry, $field) {
        $fresh = Entry::find()->id($entry->id)->status(null)->one();
        $layoutField = $fresh->getFieldLayout()->getFieldByHandle($field->handle);
        return $layoutField->serializeValueForDb($fresh->getFieldValue($field->handle), $fresh);
    };

    $plugin = ContentOps::getInstance();
    $before = $read();
    $changeset = $plugin->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section->handle]]),
        [new Operation(['target' => $field->handle, 'operator' => $operator, 'operation' => $operation, 'options' => $options])],
    );
    expect($changeset->getCount(ChangeStatus::Pending->value))->toBe(1, 'Expected one pending change: ' . json_encode($changeset->counts));

    $plugin->getChangesets()->applyNow($changeset->id);
    expect($plugin->getChangesets()->getChangesetById($changeset->id)->status)->toBe(ChangesetStatus::Applied);
    $afterApply = $read();

    $plugin->getChangesets()->undoNow($changeset->id);
    expect($plugin->getChangesets()->getChangesetById($changeset->id)->status)->toBe(ChangesetStatus::Undone);

    return [$before, $afterApply, $read()];
}

$options = [
    ['label' => 'Red', 'value' => 'red'],
    ['label' => 'Green', 'value' => 'green'],
    ['label' => 'Blue', 'value' => 'blue'],
];

it('round-trips every operator through real fields', function(string $type, array $config, mixed $initial, string $operator, string $operation, array $options, mixed $expected) {
    [$before, $afterApply, $afterUndo] = roundTrip($type, $config, $initial, $operator, $operation, $options);

    expect(Values::encode($afterApply))->toBe(Values::encode($expected))
        ->and(Values::encode($afterUndo))->toBe(Values::encode($before));
})->with([
    'number increase' => [Number::class, ['decimals' => 0], 10, 'number', 'increase', ['value' => 5], 15],
    'number percent' => [Number::class, ['decimals' => 2], 100, 'number', 'decreasePercent', ['value' => 12.5], 87.5],
    'lightswitch toggle' => [Lightswitch::class, [], true, 'lightswitch', 'toggle', [], false],
    'dropdown set' => [Dropdown::class, ['options' => $options], 'red', 'options', 'set', ['value' => 'blue'], 'blue'],
    'checkboxes add keeps option order' => [Checkboxes::class, ['options' => $options], ['blue'], 'options', 'add', ['values' => ['red']], ['red', 'blue']],
    'checkboxes remove' => [Checkboxes::class, ['options' => $options], ['red', 'green'], 'options', 'remove', ['values' => ['red']], ['green']],
    'date shift' => [Date::class, ['showTime' => true], new DateTime('2026-06-10 16:00:00', new DateTimeZone('UTC')), 'date', 'shift', ['days' => 1], ['date' => '2026-06-11 16:00:00']],
]);

it('reports options the field does not have as failed changes', function() use ($options) {
    $field = FieldFactory::factory()->type(Dropdown::class)->set(['options' => $options])->create();
    $section = SectionFactory::factory()->fields($field)->create();
    EntryFactory::factory()->section($section->handle)->set($field->handle, 'red')->create();

    $changeset = ContentOps::getInstance()->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section->handle]]),
        [new Operation(['target' => $field->handle, 'operator' => 'options', 'operation' => 'set', 'options' => ['value' => 'purple']])],
    );
    $change = ContentOps::getInstance()->getChangesets()->getChanges($changeset->id)[0];

    expect($change->status)->toBe(ChangeStatus::Failed->value)
        ->and($change->error)->toContain('purple');
});

it('shifts dates across DST keeping the local time', function() {
    $operator = new \romanavr\contentops\operators\DateOperator();
    $originalTimeZone = Craft::$app->getTimeZone();
    Craft::$app->setTimeZone('Europe/Amsterdam');

    try {
        // 09:00 in Amsterdam on Mar 28 (CET, UTC+1) → 09:00 on Apr 4 (CEST, UTC+2).
        $shifted = $operator->apply('2026-03-28 08:00:00', new Operation(['target' => 'postDate', 'operator' => 'date', 'operation' => 'shift', 'options' => ['days' => 7]]));
    } finally {
        Craft::$app->setTimeZone($originalTimeZone);
    }

    expect($shifted)->toBe('2026-04-04 07:00:00');
});

it('undoes relation changes surgically', function() {
    $targetSection = SectionFactory::factory()->create();
    [$a, $b, $c, $d] = EntryFactory::factory()->section($targetSection->handle)->count(4)->create()->all();

    $field = FieldFactory::factory()->type(EntriesField::class)->create();
    $section = SectionFactory::factory()->fields($field)->create();
    $entry = EntryFactory::factory()->section($section->handle)->set($field->handle, [$a->id])->create();
    $plugin = ContentOps::getInstance();

    $changeset = $plugin->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section->handle]]),
        [new Operation(['target' => $field->handle, 'operator' => 'relation', 'operation' => 'add', 'options' => ['ids' => [$b->id, $c->id]]])],
    );
    $plugin->getChangesets()->applyNow($changeset->id);

    // Someone relates D by hand after the bulk edit.
    $edited = Entry::find()->id($entry->id)->one();
    $edited->setFieldValue($field->handle, [$a->id, $b->id, $c->id, $d->id]);
    Craft::$app->getElements()->saveElement($edited);

    $plugin->getChangesets()->undoNow($changeset->id);
    $ids = Entry::find()->id($entry->id)->one()->getFieldValue($field->handle)->ids();

    expect($ids)->toBe([$a->id, $d->id]);
});

it('edits native attributes', function() {
    $section = SectionFactory::factory()->create();
    $entry = EntryFactory::factory()->section($section->handle)->title('Hello World')->create();
    $plugin = ContentOps::getInstance();

    $changeset = $plugin->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section->handle]]),
        [
            new Operation(['target' => 'slug', 'operator' => 'text', 'operation' => 'pattern', 'options' => ['pattern' => '{title}-2026']]),
            new Operation(['target' => 'enabledForSite', 'operator' => 'lightswitch', 'operation' => 'off']),
            new Operation(['target' => 'postDate', 'operator' => 'date', 'operation' => 'set', 'options' => ['value' => '2030-01-01T09:00:00+00:00']]),
        ],
    );
    $plugin->getChangesets()->applyNow($changeset->id);
    $fresh = Entry::find()->id($entry->id)->status(null)->one();

    expect($fresh->slug)->toBe('hello-world-2026')
        ->and($fresh->getEnabledForSite())->toBeFalse()
        ->and($fresh->postDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'))->toBe('2030-01-01 09:00');

    $plugin->getChangesets()->undoNow($changeset->id);
    $restored = Entry::find()->id($entry->id)->status(null)->one();

    expect($restored->slug)->toBe($entry->slug)
        ->and($restored->getEnabledForSite())->toBeTrue()
        ->and($restored->postDate->getTimestamp())->toBe($entry->postDate->getTimestamp());
});
