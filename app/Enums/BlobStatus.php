<?php

namespace App\Enums;

enum BlobStatus: string
{
    /** Stored as uploaded, waiting for the optimizer. Fully servable already. */
    case Pending = 'pending';

    /** Re-encoded and compressed. */
    case Ready = 'ready';

    /** Optimization failed; the original bytes are still served. */
    case Failed = 'failed';
}
