<?php

namespace ServerPulse\Agent\Contracts;

/**
 * A collector that contributes extra top-level payload keys besides its
 * own section (for example channel discovery metadata).
 */
interface ProvidesExtraPayload
{
    /**
     * @return array<string, mixed>
     */
    public function extraPayload(): array;
}
