<?php

namespace Keepsuit\Spyglass\Http;

use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\Date;

/**
 * The few widget shapes the dashboard draws without code of its own for each module.
 *
 * Links and action URLs are relative to the API root, which mirrors the dashboard's own paths. In a table they
 * are templates: `{column}` is replaced with the row's value. Actions are POST requests. Datetimes are ISO 8601 UTC.
 *
 * @internal
 *
 * @phpstan-type Field array{key: string, label: string, type: string}
 * @phpstan-type Action array{key: string, label: string, url: string}
 * @phpstan-type Filter array{key: string, label: string, value: ?string, options: ?list<array{value: string, label: string}>}
 */
final class Descriptor
{
    /**
     * Filters are query parameters applied to every widget of the page, as is `page` to its paginated table.
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
     * @return array<string, mixed>
     */
    public static function metric(string $key, string $label, int|float|string|null $value, ?string $unit = null): array
    {
        return ['type' => 'metric', 'key' => $key, 'label' => $label, 'value' => $value, 'unit' => $unit];
    }

    /**
     * @param  list<Field>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  Paginator<int, mixed>|null  $paginator
     * @param  list<Action>  $actions
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
    ): array {
        return [
            'type' => 'table',
            'key' => $key,
            'label' => $label,
            'columns' => $columns,
            'rows' => self::rows($columns, $rows),
            'pagination' => $paginator === null ? null : [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
                'total' => $paginator instanceof LengthAwarePaginator ? $paginator->total() : null,
            ],
            'link' => $link,
            'actions' => $actions,
        ];
    }

    /**
     * Points share the `$x` key, a datetime, and a key per line.
     *
     * @param  list<array{key: string, label: string}>  $lines
     * @param  iterable<array<string, mixed>>  $points
     * @return array<string, mixed>
     */
    public static function series(string $key, string $label, string $x, array $lines, iterable $points): array
    {
        $columns = [self::field($x, $x, 'datetime')];

        foreach ($lines as $line) {
            $columns[] = self::field($line['key'], $line['label'], 'number');
        }

        return ['type' => 'series', 'key' => $key, 'label' => $label, 'x' => $x, 'lines' => $lines, 'points' => self::rows($columns, $points)];
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
        ];
    }

    /**
     * `$type` is one of text, number, boolean, datetime, duration (milliseconds), status or json.
     *
     * @return Field
     */
    public static function field(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    /**
     * @param  array<string, string>|null  $options  value => label; null for free text
     * @return Filter
     */
    public static function filter(string $key, string $label, ?string $value, ?array $options = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'options' => $options === null ? null : array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($options), $options),
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
     * Rows keep every value, so link and action templates can use columns that are not shown.
     *
     * @param  list<Field>  $fields
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected static function rows(array $fields, iterable $rows): array
    {
        $types = array_column($fields, 'type', 'key');
        $result = [];

        foreach ($rows as $row) {
            foreach ($types as $key => $type) {
                $row[$key] = self::value($row[$key] ?? null, $type);
            }

            $result[] = $row;
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
            default => $value,
        };
    }
}
