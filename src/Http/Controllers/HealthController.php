<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Keepsuit\Spyglass\Http\Descriptor as D;
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
        return D::page('Health', [
            D::table('checks', 'Checks', [
                D::field('label', 'Check'),
                D::field('status', 'Status', 'status'),
                D::field('value', 'Value'),
                D::field('message', 'Message'),
            ], $this->queries->checks()),
        ]);
    }
}
