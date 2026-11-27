<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\Request;
use Keepsuit\Spyglass\Dashboard\Widget;
use Keepsuit\Spyglass\Modules\Logs\LogQueries;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Support\Hourly;

/**
 * @internal
 */
class LogsController extends Controller
{
    public const array TONES = [
        'emergency' => 'error', 'alert' => 'error', 'critical' => 'error', 'error' => 'error',
        'warning' => 'warning', 'notice' => 'info', 'info' => 'info', 'debug' => 'info',
    ];

    public const string NOTE = 'Recorded entries: at most one a minute per message and source.';

    public function __construct(Spyglass $spyglass, protected LogQueries $queries)
    {
        parent::__construct($spyglass);
    }

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['range' => 'in:'.implode(',', array_keys(self::HOURS)), 'level' => 'in:'.implode(',', array_keys(self::TONES)), 'scope' => '']);
        $range = $filters['range'] ?? '24h';
        $from = Hourly::start(self::HOURS[$range]);
        $by = array_filter(['level' => $filters['level'] ?? null, 'scope' => $filters['scope'] ?? null], fn ($value) => $value !== null);
        $entries = $this->queries->entries($by, page: $this->page($request));

        return Widget::page('Logs', [
            self::chart($this->queries->hourly($from, $by)),
            Widget::table('entries', 'Logs', [
                Widget::field('logged_at', 'Logged at', 'datetime'),
                Widget::field('level', 'Level', 'status', self::TONES),
                Widget::field('message', 'Message'),
                Widget::field('source_type', 'Source type'),
                Widget::field('source', 'Source'),
                Widget::field('scope_label', 'Scope'),
            ], $this->rows($entries->items()), $entries, link: 'logs/{id}', filters: ['level', 'scope']),
        ], [
            $this->rangeFilter($range),
            Widget::filter('level', 'Level', $filters['level'] ?? null, array_map(ucfirst(...), array_combine(array_keys(self::TONES), array_keys(self::TONES)))),
            Widget::filter('scope', 'Scope', $filters['scope'] ?? null, suggestions: $this->suggestions($this->queries->scopes(), scopes: true)),
        ]);
    }

    /**
     * Entries per hour as bars, levels folded into their tone.
     *
     * @param  list<array<string, mixed>>  $hours  as `LogQueries::hourly()`
     * @return array<string, mixed>
     */
    public static function chart(array $hours, string $label = 'Log entries per hour'): array
    {
        $lines = ['error' => 'Errors', 'warning' => 'Warnings', 'info' => 'Notices'];

        $points = array_map(function (array $hour) use ($lines) {
            $point = ['bucket' => $hour['bucket'], ...array_fill_keys(array_keys($lines), 0)];

            foreach (self::TONES as $level => $tone) {
                $point[$tone] += $hour[$level] ?? 0;
            }

            return $point;
        }, $hours);

        return Widget::series('recorded', $label, 'bucket', array_map(
            fn (string $tone, string $label) => ['key' => $tone, 'label' => $label, 'tone' => $tone], array_keys($lines), $lines,
        ), $points, ['range', 'level', 'scope'], style: 'bars', note: self::NOTE);
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
