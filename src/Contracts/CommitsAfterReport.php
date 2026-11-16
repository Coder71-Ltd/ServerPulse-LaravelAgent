<?php

namespace ServerPulse\Agent\Contracts;

/**
 * A collector that buffers state until the report is accepted.
 *
 * Implementations must not advance cursors in `collect()`. The report
 * command calls `commit()` only after the API returns 200/202.
 */
interface CommitsAfterReport
{
    /**
     * @return bool True when the buffered state was persisted.
     */
    public function commit(): bool;
}
