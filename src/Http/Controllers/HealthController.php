<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Keepsuit\Spyglass\Dashboard\Widget;
use Keepsuit\Spyglass\Modules\Health\HealthQueries;
use Keepsuit\Spyglass\Spyglass;

/**
 * @internal
 */
class HealthController extends Controller
{
    public function __construct(Spyglass $spyglass, protected HealthQueries $queries)
    {
        parent::__construct($spyglass);
    }

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return Widget::page('Health', [
            Widget::table('checks', 'Checks', [
                Widget::field('label', 'Check'),
                Widget::field('status', 'Status', 'status', ['ok' => 'ok', 'info' => 'info', 'warning' => 'warning', 'error' => 'error']),
                Widget::field('value', 'Value'),
                Widget::field('message', 'Message'),
            ], array_map(fn (array $check) => [...$check, 'value' => $this->withUnit($check)], $this->queries->checks())),
        ]);
    }

    /**
     * Databases and caches measure a round trip in milliseconds, queues count the jobs waiting.
     *
     * @param  array{key: string, value: mixed}  $check
     */
    protected function withUnit(array $check): mixed
    {
        if (! is_int($check['value']) && ! is_float($check['value'])) {
            return $check['value'];
        }

        return match (strtok($check['key'], ':')) {
            'database', 'cache' => "{$check['value']} ms",
            'queue' => "{$check['value']} pending",
            default => $check['value'],
        };
    }
}
