<?php

namespace Keepsuit\Spyglass\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Keepsuit\Spyglass\Spyglass
 */
class Spyglass extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Keepsuit\Spyglass\Spyglass::class;
    }
}
