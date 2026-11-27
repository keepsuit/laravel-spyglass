<?php

namespace Keepsuit\Spyglass\Dashboard;

use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\Date;

/**
 * The few widget shapes the dashboard draws without code of its own for each module.
 *
 * Links and action URLs are relative to the API root, which mirrors the dashboard's own paths. In a table they
 * are templates: `{column}` is replaced with the row's value. Actions are POST requests. Datetimes are ISO 8601 UTC.
 * Each widget lists the page filters it responds to: the others do not change it.
 *
 * @internal
 *
 * @phpstan-type Field array{key: string, label: string, type: string, tones?: array<string, string>}
 * @phpstan-type Action array{key: string, label: string, url: string}
 * @phpstan-type Filter array{key: string, label: string, value: ?string, options: ?list<array{value: string, label: string}>, suggestions?: list<array{value: string, label: string}>}
 */
final class Widget
{
    /**
     * `page` selects the page of the paginated table.
     *
     * @param  list<array<string, mixed>>  $widgets
     * @param  list<Filter>  $filters
     * @return array<string, mixed>
     */
    public static function page(string $title, array $widgets, array $filters = []): array
    {
        return ['title' => $title, 'filters' => $filters, 'widgets' => $widgets];
    }

    /**
     * `$link` leads to the page with the details, `$tone` (ok, info, warning, error) flags the value. `$format`
     * `duration` reads the value as milliseconds. `$previous` is the value over the previous period, and
     * `$better` (`up` or `down`) the direction that colours its change as good.
     *
     * @param  list<string>  $filters
     * @return array<string, mixed>
     */
    public static function metric(
        string $key,
        string $label,
        int|float|string|null $value,
        array $filters = [],
        ?string $link = null,
        ?string $tone = null,
        ?string $format = null,
        int|float|null $previous = null,
        ?string $better = null,
    ): array {
        return [
            'type' => 'metric',
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'filters' => $filters,
            ...array_filter(compact('link', 'tone', 'format', 'previous', 'better'), fn ($option) => $option !== null),
        ];
    }

    /**
     * `$more` leads to the full list of a shortened table, `$empty` replaces the generic text of an empty one,
     * and `$width` `half` lets two tables share a row.
     *
     * @param  list<Field>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  Paginator<int, mixed>|null  $paginator
     * @param  list<Action>  $actions
     * @param  list<string>  $filters
     * @return array<string, mixed>
     */
    public static function table(
        string $key,
        string $label,
        array $columns,
        iterable $rows,
        ?Paginator $paginator = null,
        ?string $link = null,
        array $actions = [],
        array $filters = [],
        ?string $more = null,
        ?string $empty = null,
        ?string $width = null,
    ): array {
        // Row values the templates need, shown or not.
        preg_match_all('/\{(\w+)\}/', $link.implode(' ', array_column($actions, 'url')), $placeholders);

        return [
            'type' => 'table',
            'key' => $key,
            'label' => $label,
            'columns' => $columns,
            'rows' => self::rows($columns, $rows, $placeholders[1]),
            'pagination' => $paginator === null ? null : [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
                'total' => $paginator instanceof LengthAwarePaginator ? $paginator->total() : null,
            ],
            'link' => $link,
            'actions' => $actions,
            'filters' => $filters,
            ...array_filter(['more' => $more, 'empty' => $empty, 'width' => $width], fn ($option) => $option !== null),
        ];
    }

    /**
     * Points share the `$x` key, a datetime, and a key per line. A line can take the colour of a tone. `$style`
     * `bars` stacks the lines as bars, one per point. `$note` says how to read the values.
     *
     * @param  list<array{key: string, label: string, tone?: string}>  $lines
     * @param  iterable<array<string, mixed>>  $points
     * @param  list<string>  $filters
     * @return array<string, mixed>
     */
    public static function series(
        string $key,
        string $label,
        string $x,
        array $lines,
        iterable $points,
        array $filters = [],
        ?string $style = null,
        ?string $note = null,
        ?string $width = null,
    ): array {
        $columns = [self::field($x, $x, 'datetime')];

        foreach ($lines as $line) {
            $columns[] = self::field($line['key'], $line['label'], 'number');
        }

        return [
            'type' => 'series',
            'key' => $key,
            'label' => $label,
            'x' => $x,
            'lines' => $lines,
            'points' => self::rows($columns, $points),
            'filters' => $filters,
            ...array_filter(compact('style', 'note', 'width'), fn ($option) => $option !== null),
        ];
    }

    /**
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $values
     * @param  list<Action>  $actions
     * @return array<string, mixed>
     */
    public static function details(string $key, string $label, array $fields, array $values, array $actions = []): array
    {
        $values = self::rows($fields, [$values])[0];

        return [
            'type' => 'details',
            'key' => $key,
            'label' => $label,
            'items' => array_map(fn (array $field) => $field + ['value' => $values[$field['key']]], $fields),
            'actions' => $actions,
            'filters' => [],
        ];
    }

    /**
     * `$type` is one of text, number, boolean, datetime, duration (milliseconds), status, json, link
     * (`{label, url}`, url null when not linkable) or runs (a list of `{status, started_at}`, oldest first). A status maps its values to the tones ok, info, warning and error; others are neutral.
     *
     * @param  array<string, string>|null  $tones
     * @return Field
     */
    public static function field(string $key, string $label, string $type = 'text', ?array $tones = null): array
    {
        $field = ['key' => $key, 'label' => $label, 'type' => $type];

        if ($tones !== null) {
            $field['tones'] = $tones;
        }

        return $field;
    }

    /**
     * Free text filters can suggest known values while still accepting any other.
     *
     * @param  array<string, string>|null  $options  value => label; null for free text
     * @param  array<string, string>|null  $suggestions  value => label, for free text
     * @return Filter
     */
    public static function filter(string $key, string $label, ?string $value, ?array $options = null, ?array $suggestions = null): array
    {
        $list = fn (array $items) => array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($items), $items);

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'options' => $options === null ? null : $list($options),
            ...($suggestions === null ? [] : ['suggestions' => $list($suggestions)]),
        ];
    }

    /**
     * @return Action
     */
    public static function action(string $key, string $label, string $url): array
    {
        return ['key' => $key, 'label' => $label, 'url' => $url];
    }

    /**
     * A null `$url` shows the label without a link.
     *
     * @return array{label: string, url: ?string}|null
     */
    public static function link(?string $label, ?string $url): ?array
    {
        return $label === null ? null : ['label' => $label, 'url' => $url];
    }

    /**
     * Only declared values leave the server: rows can hold payloads or context that no column shows.
     *
     * @param  list<Field>  $fields
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>  $keep  undeclared keys kept as they are
     * @return list<array<string, mixed>>
     */
    protected static function rows(array $fields, iterable $rows, array $keep = []): array
    {
        $types = array_column($fields, 'type', 'key');
        $result = [];

        foreach ($rows as $row) {
            $values = array_intersect_key($row, array_flip($keep));

            foreach ($types as $key => $type) {
                $values[$key] = self::value($row[$key] ?? null, $type);
            }

            $result[] = $values;
        }

        return $result;
    }

    protected static function value(mixed $value, string $type): mixed
    {
        return match (true) {
            $value === null => null,
            // Stored datetimes are UTC strings without a zone, which a browser would read as local time.
            $type === 'datetime' => ($value instanceof DateTimeInterface ? Date::instance($value) : Date::parse((string) $value, 'UTC'))->utc()->toIso8601ZuluString('millisecond'),
            // The log context is cut at a fixed length, so it may not decode: then it is shown as stored.
            $type === 'json' && is_string($value) => json_decode($value, true) ?? $value,
            $type === 'number' && is_numeric($value) => $value + 0,
            $type === 'duration' && is_numeric($value) => (int) $value,
            $type === 'boolean' => (bool) $value,
            $type === 'runs' && is_array($value) => array_map(fn (array $run) => ['status' => $run['status'], 'started_at' => self::value($run['started_at'], 'datetime')], $value),
            default => $value,
        };
    }
}
