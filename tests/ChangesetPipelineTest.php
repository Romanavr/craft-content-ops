<?php

use craft\elements\Entry;
use craft\fields\PlainText;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\Field as FieldFactory;
use markhuot\craftpest\factories\Section as SectionFactory;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetStatus;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;

/**
 * Creates a section with a plain text field and entries, returning [sectionHandle, fieldHandle, Entry[]].
 */
function seedSection(array $summaries): array
{
    $field = FieldFactory::factory()->type(PlainText::class)->create();
    $section = SectionFactory::factory()->fields($field)->create();
    $entries = [];

    foreach ($summaries as $i => $summary) {
        $entries[] = EntryFactory::factory()
            ->section($section->handle)
            ->title("Entry $i")
            ->set($field->handle, $summary)
            ->create();
    }

    return [$section->handle, $field->handle, $entries];
}

function previewOp(string $section, Operation ...$operations): Changeset
{
    return ContentOps::getInstance()->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section]]),
        $operations,
    );
}

function op(string $target, string $operation, array $options = []): Operation
{
    return new Operation(['target' => $target, 'operator' => 'text', 'operation' => $operation, 'options' => $options]);
}

function fresh(Entry $entry): Entry
{
    return Entry::find()->id($entry->id)->status(null)->one();
}

it('previews without writing', function() {
    [$section, $field, $entries] = seedSection(['Acme rocks', 'nothing here', 'Acme again']);

    $changeset = previewOp($section, op($field, 'replace', ['find' => 'Acme', 'replace' => 'Globex']));

    expect($changeset->status)->toBe(ChangesetStatus::Previewed)
        ->and($changeset->getCount('total'))->toBe(3)
        ->and($changeset->getCount('unchanged'))->toBe(1)
        ->and($changeset->getCount(ChangeStatus::Pending->value))->toBe(2)
        ->and(fresh($entries[0])->getFieldValue($field))->toBe('Acme rocks');
});

it('applies and undoes a changeset', function() {
    [$section, $field, $entries] = seedSection(['Acme rocks', 'Acme again']);
    $changesets = ContentOps::getInstance()->getChangesets();

    $changeset = previewOp($section, op($field, 'replace', ['find' => 'Acme', 'replace' => 'Globex']), op('title', 'append', ['value' => ' (2026)']));
    $changesets->applyNow($changeset->id);

    expect($changesets->getChangesetById($changeset->id)->status)->toBe(ChangesetStatus::Applied)
        ->and(fresh($entries[0])->getFieldValue($field))->toBe('Globex rocks')
        ->and(fresh($entries[1])->title)->toBe('Entry 1 (2026)');

    $changesets->undoNow($changeset->id);

    expect($changesets->getChangesetById($changeset->id)->status)->toBe(ChangesetStatus::Undone)
        ->and(fresh($entries[0])->getFieldValue($field))->toBe('Acme rocks')
        ->and(fresh($entries[1])->title)->toBe('Entry 1');
});

it('skips values that changed between preview and apply', function() {
    [$section, $field, $entries] = seedSection(['one', 'two']);
    $changesets = ContentOps::getInstance()->getChangesets();

    $changeset = previewOp($section, op($field, 'append', ['value' => '!']));

    $edited = fresh($entries[0]);
    $edited->setFieldValue($field, 'edited meanwhile');
    Craft::$app->getElements()->saveElement($edited);

    $changesets->applyNow($changeset->id);
    $changeset = $changesets->getChangesetById($changeset->id);

    expect($changeset->getCount(ChangeStatus::Conflict->value))->toBe(1)
        ->and($changeset->getCount(ChangeStatus::Applied->value))->toBe(1)
        ->and(fresh($entries[0])->getFieldValue($field))->toBe('edited meanwhile')
        ->and(fresh($entries[1])->getFieldValue($field))->toBe('two!');
});

it('does not undo values edited after apply unless forced', function() {
    [$section, $field, $entries] = seedSection(['one', 'two']);
    $changesets = ContentOps::getInstance()->getChangesets();

    $changeset = previewOp($section, op($field, 'set', ['value' => 'bulk']));
    $changesets->applyNow($changeset->id);

    $edited = fresh($entries[0]);
    $edited->setFieldValue($field, 'hand-edited');
    Craft::$app->getElements()->saveElement($edited);

    $changesets->undoNow($changeset->id);

    expect($changesets->getChangesetById($changeset->id)->status)->toBe(ChangesetStatus::PartiallyUndone)
        ->and(fresh($entries[0])->getFieldValue($field))->toBe('hand-edited')
        ->and(fresh($entries[1])->getFieldValue($field))->toBe('two');

    $changesets->undoNow($changeset->id, force: true);

    expect($changesets->getChangesetById($changeset->id)->status)->toBe(ChangesetStatus::Undone)
        ->and(fresh($entries[0])->getFieldValue($field))->toBe('one');
});

it('records validation failures and keeps going', function() {
    [$section, , $entries] = seedSection(['a', 'b']);
    $changesets = ContentOps::getInstance()->getChangesets();

    // Clearing a title fails validation (titles are required).
    $changeset = previewOp($section, op('title', 'clear'));
    $changesets->applyNow($changeset->id);
    $changeset = $changesets->getChangesetById($changeset->id);

    expect($changeset->getCount(ChangeStatus::Failed->value))->toBe(2)
        ->and($changeset->status)->toBe(ChangesetStatus::Failed)
        ->and(fresh($entries[0])->title)->toBe('Entry 0');
});

it('skips targets missing from the field layout', function() {
    [$section] = seedSection(['a']);

    $changeset = previewOp($section, op('doesNotExist', 'set', ['value' => 'x']));

    expect($changeset->getCount(ChangeStatus::Skipped->value))->toBe(1);
});

it('refuses to apply a changeset twice', function() {
    [$section, $field] = seedSection(['a']);
    $changesets = ContentOps::getInstance()->getChangesets();

    $changeset = previewOp($section, op($field, 'append', ['value' => '!']));
    $changesets->applyNow($changeset->id);
    $changesets->applyNow($changeset->id);
})->throws(\yii\base\InvalidArgumentException::class, 'is applied');

it('limits by element, not by element/site row', function() {
    [$section, $field] = seedSection(['a', 'b', 'c']);

    $changeset = ContentOps::getInstance()->getPreviewer()->preview(
        new Selection([
            'criteria' => ['section' => $section, 'limit' => 2],
            'siteIds' => Craft::$app->getSites()->getAllSiteIds(),
        ]),
        [op($field, 'append', ['value' => '!'])],
    );

    $elementIds = array_unique(array_map(fn($change) => $change->elementId, ContentOps::getInstance()->getChangesets()->getChanges($changeset->id)));

    expect($elementIds)->toHaveCount(2);
});
