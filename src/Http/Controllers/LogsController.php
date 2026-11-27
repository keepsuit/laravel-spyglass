<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\Request;
use Keepsuit\Spyglass\Http\Descriptor as D;
use Keepsuit\Spyglass\Modules\Logs\LogQueries;
use Keepsuit\Spyglass\Spyglass;

/**
 * @internal
 */
class LogsController extends Controller
{
    protected const array LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public function __construct(Spyglass $spyglass, protected LogQueries $queries)
    {
        parent::__construct($spyglass);
    }

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['level' => 'in:'.implode(',', self::LEVELS), 'scope' => '']);
        $entries = $this->queries->entries($filters, page: $this->page($request));

        return D::page('Logs', [
            D::table('entries', 'Logs', [
                D::field('logged_at', 'Logged at', 'datetime'),
                D::field('level', 'Level', 'status'),
                D::field('message', 'Message'),
                D::field('source_type', 'Source type'),
                D::field('source', 'Source'),
                D::field('scope', 'Scope'),
            ], $this->rows($entries->items()), $entries, link: 'logs/{id}'),
        ], [
            D::filter('level', 'Level', $filters['level'] ?? null, array_combine(self::LEVELS, array_map('ucfirst', self::LEVELS))),
            D::filter('scope', 'Scope', $filters['scope'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $id): array
    {
        $entry = $this->queries->entry($id);

        abort_if($entry === null, 404);

        $entry = $this->rows([$entry])[0];

        return D::page('Log entry', [
            D::details('entry', 'Log entry', [
                D::field('logged_at', 'Logged at', 'datetime'),
                D::field('level', 'Level', 'status'),
                D::field('message', 'Message'),
                D::field('source_type', 'Source type'),
                D::field('source', 'Source'),
                D::field('source_id', 'Source id'),
                D::field('scope', 'Scope'),
                D::field('context', 'Context', 'json'),
                D::field('log_context', 'Log context', 'json'),
            ], $entry),
            D::table('sources', 'Sources', [
                D::field('source_type', 'Type'),
                D::field('source', 'Source'),
                D::field('count', 'Count', 'number'),
                D::field('last_seen_at', 'Last seen at', 'datetime'),
            ], $this->rows($this->queries->sources((string) $entry['level'], (string) $entry['message']))),
        ]);
    }
}
