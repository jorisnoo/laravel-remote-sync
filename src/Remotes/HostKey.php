<?php

namespace Noo\LaravelRemoteSync\Remotes;

enum HostKey
{
    /** Known and matching, or the connection failed for another reason. */
    case Trusted;

    case Unknown;

    case Changed;

    /** The host key is fine but key authentication was refused. */
    case AuthFailed;
}
