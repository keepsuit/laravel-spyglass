<?php

namespace Keepsuit\Spyglass\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Keepsuit\Spyglass\Spyglass auth(\Closure $callback)
 * @method static bool check(\Illuminate\Http\Request $request)
 * @method static \Keepsuit\Spyglass\Spyglass resolveScopeUsing(\Closure|null $callback)
 * @method static \Keepsuit\Spyglass\Spyglass resolveScopeLabelsUsing(\Closure|null $callback)
 * @method static array<string, string> scopeLabels(array<int, string> $scopes)
 * @method static array{scope: ?string, context: ?string} captureContext()
 * @method static \Illuminate\Database\Connection connection()
 * @method static \Illuminate\Contracts\Cache\Repository cache()
 * @method static void insert(string $table, array<string, mixed> $row)
 * @method static void upsert(string $table, array<string, mixed> $key, array<string, mixed> $values = [], array<string, int|float> $increment = [], array<string, mixed> $max = [], array<string, mixed> $min = [])
 * @method static void flush()
 * @method static mixed ignore(\Closure $callback)
 * @method static bool isIgnoring()
 * @method static void report(string $message, \Throwable $e)
 *
 * @see \Keepsuit\Spyglass\Spyglass
 */
class Spyglass extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Keepsuit\Spyglass\Spyglass::class;
    }
}
