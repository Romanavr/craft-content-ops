<?php

use romanavr\contentops\helpers\Matcher;
use romanavr\contentops\models\MatchSpec;
use yii\base\InvalidArgumentException;

function spec(array $config): MatchSpec
{
    return new MatchSpec($config);
}

it('replaces plain text', function(string $text, array $spec, string $expected) {
    expect(Matcher::replace($text, spec($spec))[0])->toBe($expected);
})->with([
    'case-sensitive' => ['Acme and acme', ['find' => 'Acme', 'replace' => 'Globex'], 'Globex and acme'],
    'case-insensitive' => ['Acme and acme', ['find' => 'acme', 'replace' => 'Globex', 'caseSensitive' => false], 'Globex and Globex'],
    'whole word' => ['cat category cat.', ['find' => 'cat', 'replace' => 'dog', 'wholeWord' => true], 'dog category dog.'],
    'regex specials are literal' => ['a.b a*b', ['find' => 'a.b', 'replace' => 'x'], 'x a*b'],
    'unicode + emoji' => ['Grüße 👋 Grüße', ['find' => 'Grüße', 'replace' => 'Hallo'], 'Hallo 👋 Hallo'],
    'unicode whole word' => ['über überall', ['find' => 'über', 'replace' => 'unter', 'wholeWord' => true], 'unter überall'],
    'dollar in plain replacement' => ['price', ['find' => 'price', 'replace' => '$1 off'], '$1 off'],
    'tilde' => ['a~b', ['find' => '~', 'replace' => '-'], 'a-b'],
]);

it('supports regex with captures', function() {
    $spec = spec(['find' => '(\d{4})-(\d{2})', 'replace' => '$2/$1', 'regex' => true]);

    expect(Matcher::replace('Since 2024-05 and 2025-11', $spec)[0])->toBe('Since 05/2024 and 11/2025');
});

it('leaves HTML tags and reference tags alone by default', function() {
    $html = '<p>Visit <a href="http://old.test/acme">Acme</a> at {entry:12:url} Acme</p><craft-entry data-entry-id="5"></craft-entry>';

    [$result, $count] = Matcher::replace($html, spec(['find' => 'Acme', 'replace' => 'Globex']), true);

    expect($result)->toBe('<p>Visit <a href="http://old.test/acme">Globex</a> at {entry:12:url} Globex</p><craft-entry data-entry-id="5"></craft-entry>')
        ->and($count)->toBe(2);
});

it('can also replace inside href and src for domain moves', function() {
    $html = '<p><a href="http://old.test/page" title="http://old.test">http://old.test</a><img src="http://old.test/a.png"></p>';
    $spec = spec(['find' => 'http://old.test', 'replace' => 'https://new.test', 'html' => MatchSpec::HTML_TEXT_AND_LINKS]);

    expect(Matcher::replace($html, $spec, true)[0])
        ->toBe('<p><a href="https://new.test/page" title="http://old.test">https://new.test</a><img src="https://new.test/a.png"></p>');
});

it('lists matches with context and skips excluded ones', function() {
    $text = 'One Acme, two Acme, three Acme.';
    $spec = spec(['find' => 'Acme', 'replace' => 'Globex']);

    $matches = Matcher::findAll($text, $spec);

    expect($matches)->toHaveCount(3)
        ->and($matches[1])->toMatchArray(['index' => 1, 'match' => 'Acme', 'replacement' => 'Globex', 'before' => 'One Acme, two ', 'after' => ', three Acme.'])
        ->and(Matcher::replace($text, $spec, false, [1]))->toBe(['One Globex, two Acme, three Globex.', 2]);
});

it('numbers matches the same way in findAll and replace for HTML with links', function() {
    $html = '<a href="http://old.test/x">http://old.test</a> http://old.test';
    $spec = spec(['find' => 'http://old.test', 'replace' => 'https://new.test', 'html' => MatchSpec::HTML_TEXT_AND_LINKS]);

    $matches = Matcher::findAll($html, $spec, true);

    expect($matches)->toHaveCount(3)
        ->and(Matcher::replace($html, $spec, true, [0])[0])->toBe('<a href="http://old.test/x">https://new.test</a> https://new.test');
});

it('rejects invalid regexes with a friendly message', function() {
    Matcher::validate(spec(['find' => '(unclosed', 'regex' => true]));
})->throws(InvalidArgumentException::class, 'The regular expression is invalid.');

it('guards against catastrophic backtracking', function() {
    $spec = spec(['find' => '(a+)+$', 'replace' => 'x', 'regex' => true]);

    Matcher::replace(str_repeat('a', 40) . 'b', $spec);
})->throws(InvalidArgumentException::class, 'too complex');
