<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\Request;
use Keepsuit\Spyglass\Dashboard\Widget;
use Keepsuit\Spyglass\Modules\Logs\LogQueries;
use Keepsuit\Spyglass\Spyglass;

/**
 * @internal
 */
class LogsController extends Controller
{
    public const array TONES = [
        'emergency' => 'error', 'alert' => 'error', 'critical' => 'error', 'error' => 'error',
        'warning' => 'warning', 'notice' => 'info', 'info' => 'info', 'debug' => 'info',
    ];

    public function __construct(Spyglass $spyglass, protected LogQueries $queries)
    {
        parent::__construct($spyglass);
    }

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['level' => 'in:'.implode(',', array_keys(self::TONES)), 'scope' => '']);
        $entries = $this->queries->entries($filters, page: $this->page($request));

        return Widget::page('Logs', [
            Widget::table('entries', 'Logs', [
                Widget::field('logged_at', 'Logged at', 'datetime'),
                Widget::field('level', 'Level', 'status', self::TONES),
                Widget::field('message', 'Message'),
                Widget::field('source_type', 'Source type'),
                Widget::field('source', 'Source'),
                Widget::field('scope_label', 'Scope'),
            ], $this->rows($entries->items()), $entries, link: 'logs/{id}', filters: ['level', 'scope']),
        ], [
            Widget::filter('level', 'Level', $filters['level'] ?? null, array_map(ucfirst(...), array_combine(array_keys(self::TONES), array_keys(self::TONES)))),
            Widget::filter('scope', 'Scope', $filters['scope'] ?? null),
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

        return Widget::page('Log entry', [
            Widget::details('entry', 'Log entry', [
                Widget::field('logged_at', 'Logged at', 'datetime'),
                Widget::field('level', 'Level', 'status', self::TONES),
                Widget::field('message', 'Message'),
                Widget::field('source_type', 'Source type'),
                Widget::field('source', 'Source'),
                Widget::field('source_id', 'Source id'),
                Widget::field('scope_label', 'Scope'),
                Widget::field('context', 'Context', 'json'),
                Widget::field('log_context', 'Log context', 'json'),
            ], $entry),
            $this->sources($this->queries->sources((string) $entry['level'], (string) $entry['message'])),
        ]);
    }
}
