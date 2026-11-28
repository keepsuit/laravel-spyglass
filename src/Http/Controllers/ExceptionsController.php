<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Keepsuit\Spyglass\Dashboard\Widget;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionQueries;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Support\Buckets;

/**
 * @internal
 */
class ExceptionsController extends Controller
{
    public const array TONES = ['unresolved' => 'error', 'resolved' => 'ok'];

    public const string NOTE = 'Recorded occurrences: at most one a minute per exception and scope.';

    public const string SCOPED_NOTE = 'Count, last seen and message come from the occurrences recorded in this scope, at most one a minute per exception: the count is approximate. Status, resolve and reopen apply to every scope.';

    public function __construct(Spyglass $spyglass, protected ExceptionQueries $queries)
    {
        parent::__construct($spyglass);
    }

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['range' => 'in:'.implode(',', array_keys(self::HOURS)), 'status' => 'in:unresolved,resolved', 'class' => '', 'scope' => '']);
        $range = $filters['range'] ?? '24h';
        $groups = $this->queries->groups(array_filter([
            'status' => match ($filters['status'] ?? null) {
                'resolved' => 'resolved',
                'unresolved' => 'unresolved',
                default => null,
            },
            'class' => $filters['class'] ?? null,
            'scope' => $filters['scope'] ?? null,
        ], fn ($value) => $value !== null), page: $this->page($request), sort: $this->sort($request, 'groups', ['count', 'last_seen_at']));

        return Widget::page('Exceptions', [
            self::chart($this->queries->hourly($from = Buckets::start(self::HOURS[$range], self::HOUR), array_intersect_key($filters, array_flip(['class', 'scope']))), $from),
            Widget::table('groups', 'Exceptions', [
                Widget::field('class', 'Class'),
                Widget::field('message', 'Message'),
                Widget::field('location', 'Location', 'code'),
                Widget::field('count', 'Count', 'number', sortable: true),
                Widget::field('last_seen_at', 'Last seen at', 'datetime', sortable: true),
                Widget::field('status', 'Status', 'status', self::TONES),
            ], array_map(self::group(...), $this->rows($groups->items())), $groups, link: 'exceptions/{fingerprint}'.self::scopeQuery($filters['scope'] ?? null),
                filters: ['status', 'class', 'scope'], note: isset($filters['scope']) ? self::SCOPED_NOTE : null),
        ], [
            $this->rangeFilter($range),
            Widget::filter('status', 'Status', $filters['status'] ?? null, ['unresolved' => 'Unresolved', 'resolved' => 'Resolved']),
            Widget::filter('class', 'Class', $filters['class'] ?? null, suggestions: $this->suggestions($this->queries->classes())),
            Widget::filter('scope', 'Scope', $filters['scope'] ?? null, suggestions: $this->suggestions($this->queries->scopes(), scopes: true)),
        ]);
    }

    /**
     * Recorded occurrences as bars.
     *
     * @param  list<array<string, mixed>>  $rows  as `ExceptionQueries::hourly()`
     * @return array<string, mixed>
     */
    public static function chart(array $rows, CarbonInterface $from, string $label = 'Recorded exceptions'): array
    {
        return Widget::series('recorded', $label, 'bucket', [['key' => 'count', 'label' => 'Exceptions', 'tone' => 'error']],
            Buckets::fill($rows, $from, self::HOUR, ['count']), ['range', 'class', 'scope'], style: 'bars', note: self::NOTE, step: self::HOUR);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request, string $fingerprint): array
    {
        $page = $this->page($request);
        $scope = $this->filters($request, ['scope' => ''])['scope'] ?? null;
        $group = $this->queries->group($fingerprint, $scope);

        abort_if($group === null, 404);

        $group = self::group((array) $group);
        $occurrences = $this->queries->occurrences($fingerprint, page: $page, scope: $scope);
        $everyScope = $scope === null ? '' : ' in every scope';

        return Widget::page($group['class'], [
            Widget::details('group', 'Exception', [
                Widget::field('class', 'Class', 'code'),
                Widget::field('message', 'Message'),
                Widget::field('location', 'Location', 'code'),
                Widget::field('count', 'Count', 'number'),
                Widget::field('status', 'Status', 'status', self::TONES),
                Widget::field('first_seen_at', 'First seen at', 'datetime'),
                Widget::field('last_seen_at', 'Last seen at', 'datetime'),
                Widget::field('resolved_at', 'Resolved at', 'datetime'),
            ], $group, [
                $group['status'] === 'resolved'
                    ? Widget::action('reopen', "Reopen{$everyScope}", "exceptions/{$fingerprint}/reopen")
                    : Widget::action('resolve', "Resolve{$everyScope}", "exceptions/{$fingerprint}/resolve"),
            ], note: $scope === null ? null : self::SCOPED_NOTE),
            $this->sources($this->queries->sources($fingerprint)),
            Widget::table('occurrences', 'Occurrences', [
                Widget::field('occurred_at', 'Occurred at', 'datetime'),
                Widget::field('message', 'Message'),
                Widget::field('cause_class', 'Cause', 'code'),
                Widget::field('cause_message', 'Cause message'),
                Widget::field('source_type', 'Source type'),
                Widget::field('source', 'Source'),
                Widget::field('user_id', 'User'),
                Widget::field('scope_label', 'Scope'),
            ], $this->rows($occurrences->items()), $occurrences, filters: ['scope'], expand: [
                Widget::field('context', 'Context', 'json'),
                Widget::field('trace', 'Trace', 'json'),
            ]),
        ], [
            Widget::filter('scope', 'Scope', $scope, suggestions: $this->suggestions($this->queries->scopes(), scopes: true)),
        ]);
    }

    protected static function scopeQuery(?string $scope): string
    {
        return $scope === null ? '' : '?scope='.rawurlencode($scope);
    }

    public function resolve(string $fingerprint): Response
    {
        return $this->setResolved($fingerprint, true);
    }

    public function reopen(string $fingerprint): Response
    {
        return $this->setResolved($fingerprint, false);
    }

    /**
     * Idempotent: a group already in that state is left as it is.
     */
    protected function setResolved(string $fingerprint, bool $resolved): Response
    {
        abort_if($this->queries->group($fingerprint) === null, 404);

        $resolved ? $this->queries->resolve($fingerprint) : $this->queries->unresolve($fingerprint);

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    public static function group(array $group): array
    {
        return [
            ...$group,
            'location' => $group['file'] === null ? null : "{$group['file']}:{$group['line']}",
            'status' => $group['resolved_at'] === null ? 'unresolved' : 'resolved',
        ];
    }
}
