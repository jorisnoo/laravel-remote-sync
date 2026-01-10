<?php

namespace Noo\LaravelRemoteSync\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Noo\LaravelRemoteSync\LaravelRemoteSync
 */
class LaravelRemoteSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Noo\LaravelRemoteSync\LaravelRemoteSync::class;
    }
}
