<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Webrek\Idempotency\Tests\TestCase;

class BladeDirectiveTest extends TestCase
{
    public function test_it_renders_a_hidden_input_with_a_fresh_uuid_on_every_render(): void
    {
        $first = Blade::render('@idempotencyKey');
        $second = Blade::render('@idempotencyKey');

        $this->assertMatchesRegularExpression(
            '/^<input type="hidden" name="_idempotency_key" value="[0-9a-f-]{36}">$/',
            trim($first),
        );

        $this->assertNotSame($first, $second);
    }

    public function test_the_field_name_is_html_escaped(): void
    {
        config(['idempotency.input' => 'a"b']);

        $this->assertStringContainsString('name="a&quot;b"', Blade::render('@idempotencyKey'));
    }

    public function test_it_renders_nothing_when_the_input_is_disabled(): void
    {
        config(['idempotency.input' => null]);

        $this->assertSame('', trim(Blade::render('@idempotencyKey')));
    }
}
