<?php

use AltDesign\SearchService\JourneyState;

it('starts nowhere', function () {
    expect(JourneyState::start()->atStart())->toBeTrue()
        ->and(JourneyState::start()->parameter())->toBe('');
});

it('reads a node on its own', function () {
    $state = JourneyState::from('wedding-hire');

    expect($state->node)->toBe('wedding-hire')
        ->and($state->answers)->toBe([])
        ->and($state->atStart())->toBeFalse();
});

it('reads a node and what has been settled', function () {
    $state = JourneyState::from('wedding-hire!range:Weddings|colour:gold');

    expect($state->node)->toBe('wedding-hire')
        ->and($state->answers)->toBe([
            ['facet' => 'range', 'value' => 'Weddings'],
            ['facet' => 'colour', 'value' => 'gold'],
        ]);
});

it('goes out in the form it came in', function () {
    $parameter = 'wedding-hire!range:Weddings|colour:gold';

    expect(JourneyState::from($parameter)->parameter())->toBe($parameter);
});

it('survives a value holding the separators', function () {
    $state = JourneyState::from([
        'node' => 'wedding-hire',
        'answers' => [['facet' => 'colour', 'value' => 'black | white: both']],
    ]);

    $round = JourneyState::from($state->parameter());

    expect($round->answers)->toBe([['facet' => 'colour', 'value' => 'black | white: both']]);
});

it('drops a pair it cannot read rather than breaking the page', function () {
    $state = JourneyState::from('wedding-hire!range|colour:gold|:empty|facet:');

    expect($state->node)->toBe('wedding-hire')
        ->and($state->answers)->toBe([['facet' => 'colour', 'value' => 'gold']]);
});

it('treats an empty parameter as the start', function () {
    expect(JourneyState::from('')->atStart())->toBeTrue()
        ->and(JourneyState::from(null)->atStart())->toBeTrue()
        ->and(JourneyState::from('   ')->atStart())->toBeTrue();
});

it('reads the array shape the service answers with', function () {
    $state = JourneyState::from([
        'node' => 'catering',
        'answers' => [['facet' => 'range', 'value' => 'Catering Hire']],
    ]);

    expect($state->toQuery())->toBe([
        'node' => 'catering',
        'answers' => [['facet' => 'range', 'value' => 'Catering Hire']],
        'wants' => [],
        'order' => null,
    ]);
});

it('carries the words of the conversation through the url', function () {
    $parameter = 'seating!range:Seating Hire!metal|chair';

    $state = JourneyState::from($parameter);

    expect($state->node)->toBe('seating')
        ->and($state->answers)->toBe([['facet' => 'range', 'value' => 'Seating Hire']])
        ->and($state->wants)->toBe(['metal', 'chair']);
});

it('carries words with nothing settled', function () {
    $state = JourneyState::from(['node' => 'seating', 'wants' => ['metal', 'chair']]);

    $round = JourneyState::from($state->parameter());

    expect($round->wants)->toBe(['metal', 'chair'])
        ->and($round->answers)->toBe([]);
});

it('encodes a word holding a separator', function () {
    $state = JourneyState::from(['node' => 'a', 'wants' => ['black | white']]);

    expect(JourneyState::from($state->parameter())->wants)->toBe(['black | white']);
});

it('caps how many words the url carries', function () {
    $many = array_map(fn (int $i): string => "word{$i}", range(1, 30));

    expect(JourneyState::from(['node' => 'a', 'wants' => $many])->wants)->toHaveCount(12);
});

it('caps how many answers one parameter can carry', function () {
    $pairs = [];

    for ($i = 1; $i <= 40; $i++) {
        $pairs[] = "facet-{$i}:value-{$i}";
    }

    expect(JourneyState::from('a-node!'.implode('|', $pairs))->answers)->toHaveCount(20);
});

it('caps the length of anything submitted', function () {
    $state = JourneyState::from(str_repeat('a', 200).'!'.str_repeat('b', 200).':'.str_repeat('c', 200));

    expect(mb_strlen((string) $state->node))->toBe(64)
        ->and(mb_strlen($state->answers[0]['facet']))->toBe(64)
        ->and(mb_strlen($state->answers[0]['value']))->toBe(64);
});

it('unsettles one thing and leaves the rest', function () {
    $state = JourneyState::from('wedding-hire!range:Weddings|colour:gold')->without('colour', 'gold');

    expect($state->answers)->toBe([['facet' => 'range', 'value' => 'Weddings']])
        ->and($state->node)->toBe('wedding-hire');
});

it('builds a query string a template can drop into an href', function () {
    $query = JourneyState::from('wedding-hire!range:Weddings')->query('gold');

    expect($query)->toBe('message=gold&state=wedding-hire%21range%3AWeddings');
});

it('leaves the message out of a link when there is none', function () {
    expect(JourneyState::from('wedding-hire')->query(''))->toBe('state=wedding-hire');
});

it('encodes a value that would otherwise break the parameter', function () {
    $state = JourneyState::from(['node' => 'a', 'answers' => [['facet' => 'colour', 'value' => 'black & white']]]);

    expect($state->parameter())->toBe('a!colour:black%20%26%20white');
});

it('carries the order in the parameter', function () {
    $state = JourneyState::from([
        'node' => 'wedding-hire',
        'answers' => [['facet' => 'range', 'value' => 'Weddings']],
        'wants' => ['chair'],
        'order' => ['name' => 'price', 'direction' => 'asc'],
    ]);

    expect($state->parameter())->toBe('wedding-hire!range:Weddings!chair!price:asc');
});

it('reads the order back out of the parameter', function () {
    $state = JourneyState::from('wedding-hire!range:Weddings!chair!price:asc');

    expect($state->order)->toBe(['name' => 'price', 'direction' => 'asc'])
        ->and($state->wants)->toBe(['chair'])
        ->and($state->node)->toBe('wedding-hire');
});

it('keeps the sections in place when an order has nothing settled before it', function () {
    $state = JourneyState::from([
        'node' => 'seating',
        'answers' => [],
        'wants' => [],
        'order' => ['name' => 'price', 'direction' => 'desc'],
    ]);

    expect($state->parameter())->toBe('seating!!!price:desc')
        ->and(JourneyState::from($state->parameter())->order)->toBe(['name' => 'price', 'direction' => 'desc']);
});

it('leaves the parameter short when there is no order', function () {
    $state = JourneyState::from(['node' => 'seating', 'answers' => [], 'wants' => ['chair']]);

    expect($state->parameter())->toBe('seating!!chair');
});

it('still reads a parameter written before orders travelled in it', function () {
    $state = JourneyState::from('wedding-hire!range:Weddings!metal|chair');

    expect($state->order)->toBeNull()
        ->and($state->wants)->toBe(['metal', 'chair'])
        ->and($state->answers)->toBe([['facet' => 'range', 'value' => 'Weddings']]);
});

it('drops a direction that is neither asc nor desc rather than reversing a price list', function () {
    expect(JourneyState::from('seating!!!price:cheapest')->order)->toBeNull()
        ->and(JourneyState::from(['node' => 'x', 'order' => ['name' => 'price', 'direction' => 'up']])->order)->toBeNull();
});

it('sends the order to the service with the rest of the state', function () {
    $state = JourneyState::from('seating!!!price:asc');

    expect($state->toQuery())->toBe([
        'node' => 'seating',
        'answers' => [],
        'wants' => [],
        'order' => ['name' => 'price', 'direction' => 'asc'],
    ]);
});
