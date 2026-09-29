<?php

use craft\elements\Entry;
use craft\fields\Dropdown;
use craft\fields\PlainText;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\Field as FieldFactory;
use markhuot\craftpest\factories\Section as SectionFactory;
use romanavr\contentops\ContentOps;
use romanavr\contentops\helpers\Diff;

it('describes targets with how many elements have them', function() {
    $shared = FieldFactory::factory()->type(PlainText::class)->create();
    $onlyA = FieldFactory::factory()->type(Dropdown::class)->set(['options' => [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']]])->create();
    $sectionA = SectionFactory::factory()->fields($shared, $onlyA)->create();
    $sectionB = SectionFactory::factory()->fields($shared)->create();
    $a = EntryFactory::factory()->section($sectionA->handle)->count(2)->create()->all();
    $b = EntryFactory::factory()->section($sectionB->handle)->create();

    $targets = collect(ContentOps::getInstance()->getTargets()->describeTargets(Entry::class, [$a[0]->id, $a[1]->id, $b->id]))->keyBy('handle');

    expect($targets[$shared->handle]['count'])->toBe(3)
        ->and($targets[$shared->handle]['operator'])->toBe('text')
        ->and($targets[$onlyA->handle]['count'])->toBe(2)
        ->and($targets[$onlyA->handle]['total'])->toBe(3)
        ->and(collect($targets[$onlyA->handle]['operations'])->pluck('handle')->all())->toBe(['set', 'clear'])
        ->and($targets['title']['group'])->toBe('Attributes')
        ->and($targets['postDate']['operator'])->toBe('date');
});

it('highlights only the changed part of a value', function() {
    [$before, $after] = Diff::inline('Hello Acme Corp world', 'Hello Globex Inc world');

    expect($before)->toBe('Hello <del>Acme Corp</del> world')
        ->and($after)->toBe('Hello <ins>Globex Inc</ins> world');
});

it('escapes HTML and trims long context', function() {
    $prefix = str_repeat('a', 100);
    [$before, $after] = Diff::inline($prefix . '<b>x</b>', $prefix . '<b>y</b>');

    expect($before)->toStartWith('…')
        ->and($before)->toContain('&lt;b&gt;<del>x</del>&lt;/b&gt;')
        ->and($after)->toContain('<ins>y</ins>');
});
