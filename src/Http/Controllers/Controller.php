<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\Request;
use Keepsuit\Spyglass\Spyglass;

/**
 * @internal
 */
abstract class Controller
{
    public function __construct(protected Spyglass $spyglass) {}

    /**
     * The non-empty filters of the request, as strings. `'0'` is a valid value.
     *
     * @template TFilter of string
     *
     * @param  array<TFilter, string>  $rules  extra rules by filter
     * @return array<TFilter, string>
     */
    protected function filters(Request $request, array $rules): array
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            ...array_map(fn (string $rule) => ['sometimes', 'nullable', 'string', ...array_filter([$rule])], $rules),
        ]);

        return array_filter(array_intersect_key($validated, $rules), fn ($value) => $value !== null && $value !== '');
    }

    protected function page(Request $request): int
    {
        return max(1, $request->integer('page', 1));
    }

    /**
     * Rows as arrays, with the label of their scope as `scope_label`.
     *
     * @param  iterable<object|array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function rows(iterable $rows): array
    {
        $rows = array_map(fn ($row) => (array) $row, is_array($rows) ? array_values($rows) : iterator_to_array($rows, false));
        $scopes = array_values(array_unique(array_filter(array_column($rows, 'scope'), 'is_string')));
        $labels = $scopes === [] ? [] : $this->spyglass->scopeLabels($scopes);

        foreach ($rows as &$row) {
            if (array_key_exists('scope', $row)) {
                $row['scope_label'] = is_string($row['scope']) ? $labels[$row['scope']] : null;
            }
        }

        return $rows;
    }
}
