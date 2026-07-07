<?php

namespace Noo\LaravelRemoteSync\Sync;

enum Direction: string
{
    case Pull = 'pull';
    case Push = 'push';
}
