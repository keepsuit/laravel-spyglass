<?php

use Keepsuit\Spyglass\Support\Text;

it('clips text to a length that PostgreSQL accepts', function () {
    $anonymous = (new class extends RuntimeException {})::class;

    expect(Text::clip($anonymous))->not->toContain("\0")
        ->and(Text::clip("caf\xC3"))->toBe('caf?')
        ->and(Text::clip('èèè', 2))->toBe('èè');
});
