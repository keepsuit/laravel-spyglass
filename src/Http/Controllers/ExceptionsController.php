<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Keepsuit\Spyglass\Http\Descriptor as D;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionQueries;
use Keepsuit\Spyglass\Spyglass;

/**
 * @internal
 */
class ExceptionsController extends Controller
{
    public function __construct(Spyglass $spyglass, protected ExceptionQueries $queries)
    {
        parent::__construct($spyglass);
    }

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['status' => 'in:unresolved,resolved', 'class' => '', 'scope' => '']);
        $groups = $this->queries->groups(array_filter([
            'status' => match ($filters['status'] ?? null) {
                'resolved' => 'resolved',
                'unresolved' => 'unresolved',
                default => null,
            },
            'class' => $filters['class'] ?? null,
            'scope' => $filters['scope'] ?? null,
        ], fn ($value) => $value !== null), page: $this->page($request));

        return D::page('Exceptions', [
            D::table('groups', 'Exceptions', [
                D::field('class', 'Class'),
                D::field('message', 'Message'),
                D::field('location', 'Location'),
                D::field('count', 'Count', 'number'),
                D::field('last_seen_at', 'Last seen at', 'datetime'),
                D::field('status', 'Status', 'status'),
            ], array_map($this->group(...), $this->rows($groups->items())), $groups, link: 'exceptions/{fingerprint}'),
        ], [
            D::filter('status', 'Status', $filters['status'] ?? null, ['unresolved' => 'Unresolved', 'resolved' => 'Resolved']),
            D::filter('class', 'Class', $filters['class'] ?? null),
            D::filter('scope', 'Scope', $filters['scope'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request, string $fingerprint): array
    {
        $this->filters($request, []);
        $group = $this->queries->group($fingerprint);

        abort_if($group === null, 404);

        $group = $this->group((array) $group);
        $occurrences = $this->queries->occurrences($fingerprint, page: $this->page($request));

        return D::page($group['class'], [
            D::details('group', 'Exception', [
                D::field('class', 'Class'),
                D::field('message', 'Message'),
                D::field('location', 'Location'),
                D::field('count', 'Count', 'number'),
                D::field('status', 'Status', 'status'),
                D::field('first_seen_at', 'First seen at', 'datetime'),
                D::field('last_seen_at', 'Last seen at', 'datetime'),
                D::field('resolved_at', 'Resolved at', 'datetime'),
            ], $group, [
                $group['status'] === 'resolved'
                    ? D::action('reopen', 'Reopen', "exceptions/{$fingerprint}/reopen")
                    : D::action('resolve', 'Resolve', "exceptions/{$fingerprint}/resolve"),
            ]),
            D::table('sources', 'Sources', [
                D::field('source_type', 'Type'),
                D::field('source', 'Source'),
                D::field('count', 'Count', 'number'),
                D::field('last_seen_at', 'Last seen at', 'datetime'),
            ], $this->rows($this->queries->sources($fingerprint))),
            D::table('occurrences', 'Occurrences', [
                D::field('occurred_at', 'Occurred at', 'datetime'),
                D::field('message', 'Message'),
                D::field('cause_class', 'Cause'),
                D::field('cause_message', 'Cause message'),
                D::field('source_type', 'Source type'),
                D::field('source', 'Source'),
                D::field('user_id', 'User'),
                D::field('scope_label', 'Scope'),
                D::field('context', 'Context', 'json'),
                D::field('trace', 'Trace', 'json'),
            ], $this->rows($occurrences->items()), $occurrences),
        ]);
    }

    public function resolve(string $fingerprint): Response
    {
        abort_if($this->queries->group($fingerprint) === null, 404);

        $this->queries->resolve($fingerprint);

        return response()->noContent();
    }

    public function reopen(string $fingerprint): Response
    {
        abort_if($this->queries->group($fingerprint) === null, 404);

        $this->queries->unresolve($fingerprint);

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    protected function group(array $group): array
    {
        return [
            ...$group,
            'location' => $group['file'] === null ? null : "{$group['file']}:{$group['line']}",
            'status' => $group['resolved_at'] === null ? 'unresolved' : 'resolved',
        ];
    }
}
