<?php

use romanavr\contentops\errors\ConflictException;
use romanavr\contentops\helpers\Values;
use romanavr\contentops\models\Operation;
use romanavr\contentops\operators\TextOperator;
use yii\base\InvalidArgumentException;

function textOp(string $operation, array $options = []): Operation
{
    return new Operation(['target' => 'title', 'operator' => 'text', 'operation' => $operation, 'options' => $options]);
}

it('applies text operations', function(mixed $value, Operation $operation, mixed $expected) {
    expect((new TextOperator())->apply($value, $operation))->toBe($expected);
})->with([
    'set' => ['old', textOp('set', ['value' => 'new']), 'new'],
    'clear' => ['old', textOp('clear'), null],
    'prepend' => ['world', textOp('prepend', ['value' => 'hello ']), 'hello world'],
    'append to null' => [null, textOp('append', ['value' => '!']), '!'],
    'replace' => ['Acme and acme', textOp('replace', ['find' => 'Acme', 'replace' => 'Globex']), 'Globex and acme'],
    'replace, case-insensitive' => ['Acme and acme', textOp('replace', ['find' => 'acme', 'replace' => 'Globex', 'caseSensitive' => false]), 'Globex and Globex'],
    'unicode' => ['Grüße 👋 مرحبا', textOp('replace', ['find' => '👋', 'replace' => '🙂']), 'Grüße 🙂 مرحبا'],
]);

it('returns the original value when nothing changes', function(mixed $value, Operation $operation) {
    expect((new TextOperator())->apply($value, $operation))->toBe($value);
})->with([
    'clear null' => [null, textOp('clear')],
    'clear empty string' => ['', textOp('clear')],
    'replace without match' => ['abc', textOp('replace', ['find' => 'x', 'replace' => 'y'])],
    'set same' => ['abc', textOp('set', ['value' => 'abc'])],
]);

it('validates operations', function(Operation $operation) {
    (new TextOperator())->validateOperation($operation);
})->with([
    'unknown operation' => [textOp('explode')],
    'set without value' => [textOp('set')],
    'replace with empty find' => [textOp('replace', ['find' => '', 'replace' => 'x'])],
])->throws(InvalidArgumentException::class);

it('reverts only unchanged values', function() {
    $operator = new TextOperator();

    expect($operator->revert('new', 'old', 'new'))->toBe('old')
        ->and(fn() => $operator->revert('edited', 'old', 'new'))->toThrow(ConflictException::class);
});

it('compares values canonically', function() {
    expect(Values::equal(['a' => 1, 'b' => ['y' => 2, 'x' => 1]], ['b' => ['x' => 1, 'y' => 2], 'a' => 1]))->toBeTrue()
        ->and(Values::equal([1, 2], [2, 1]))->toBeFalse()
        ->and(Values::equal(null, ''))->toBeFalse()
        ->and(Values::equal('1', 1))->toBeFalse();
});
