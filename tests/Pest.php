<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Keepsuit\Spyglass\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function pruneEvents(?Application $app = null): array
{
    return array_values(array_filter(
        ($app ?? app())->make(Schedule::class)->events(),
        fn (Event $event) => str_contains($event->command ?? '', 'spyglass:prune'),
    ));
}

// Not `hasListeners()`: framework wildcard listeners make it always true.
function listens(Application $app, string $event): bool
{
    return array_key_exists($event, $app['events']->getRawListeners());
}
