<?php

namespace Keepsuit\Spyglass\Modules\Queue;

enum Outcome: string
{
    case Done = 'done';

    /** No longer in the failer: already retried or removed. */
    case Missing = 'missing';

    case Failed = 'failed';
}
