<?php

namespace Webrek\Idempotency\Blade;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;

/**
 * Renders the hidden input the `@idempotencyKey` Blade directive echoes, so a
 * classic HTML form submission can carry a per-submission idempotency key the
 * same way a client would send the `Idempotency-Key` header.
 */
final class KeyField
{
    public function __construct(
        protected Config $config,
    ) {}

    public function render(): string
    {
        $name = $this->config->get('idempotency.input');

        if (! is_string($name)) {
            return '';
        }

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($name, ENT_QUOTES),
            Str::uuid()->toString(),
        );
    }
}
