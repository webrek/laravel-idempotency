<?php

namespace Webrek\Idempotency\Tests\Unit;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\TestCase;
use stdClass;
use Webrek\Idempotency\Support\FlashData;

class FlashDataTest extends TestCase
{
    public function test_view_error_bags_round_trip_as_plain_arrays(): void
    {
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['sku' => ['The sku field is required.', 'Second message.']]));
        $errors->put('login', new MessageBag(['email' => ['Bad email.']]));

        $packed = FlashData::pack(['errors' => $errors]);

        $this->assertSame([
            'errors' => [
                '__idempotency_type' => 'view_error_bag',
                'bags' => [
                    'default' => ['sku' => ['The sku field is required.', 'Second message.']],
                    'login' => ['email' => ['Bad email.']],
                ],
            ],
        ], $packed);
        $this->assertSame($packed, unserialize(serialize($packed)), 'Packed flash must contain no objects.');

        $restored = FlashData::unpack($packed);

        $this->assertInstanceOf(ViewErrorBag::class, $restored['errors']);
        $this->assertSame(['The sku field is required.', 'Second message.'], $restored['errors']->getBag('default')->get('sku'));
        $this->assertSame(['Bad email.'], $restored['errors']->getBag('login')->get('email'));
        $this->assertTrue($restored['errors']->hasBag('login'));
    }

    public function test_message_bags_round_trip(): void
    {
        $packed = FlashData::pack(['bag' => new MessageBag(['a' => ['x']]), 'after' => 'kept']);

        $this->assertSame([
            'bag' => ['__idempotency_type' => 'message_bag', 'messages' => ['a' => ['x']]],
            'after' => 'kept',
        ], $packed);

        $restored = FlashData::unpack($packed);

        $this->assertInstanceOf(MessageBag::class, $restored['bag']);
        $this->assertSame(['a' => ['x']], $restored['bag']->getMessages());
    }

    public function test_scalars_and_plain_arrays_pass_through_unchanged(): void
    {
        $flash = [
            'status' => 'Order created',
            'count' => 3,
            'ratio' => 1.5,
            'flag' => true,
            'nothing' => null,
            '_old_input' => ['sku' => 'A', 'items' => [['id' => 1], ['id' => 2]]],
        ];

        $this->assertSame($flash, FlashData::pack($flash));
        $this->assertSame($flash, FlashData::unpack($flash));
    }

    public function test_other_objects_are_dropped_even_when_nested(): void
    {
        $packed = FlashData::pack([
            'status' => 'kept',
            'object' => new stdClass,
            'nested' => ['ok' => 1, 'bad' => new stdClass],
        ]);

        $this->assertSame(['status' => 'kept'], $packed);
    }

    public function test_unpack_tolerates_malformed_markers(): void
    {
        $restored = FlashData::unpack([
            'a' => ['__idempotency_type' => 'view_error_bag'],
            'b' => ['__idempotency_type' => 'message_bag', 'messages' => 'not-an-array'],
            'c' => ['__idempotency_type' => 'unknown', 'x' => 1],
        ]);

        $this->assertInstanceOf(ViewErrorBag::class, $restored['a']);
        $this->assertSame([], $restored['a']->getBags());
        $this->assertInstanceOf(MessageBag::class, $restored['b']);
        $this->assertSame([], $restored['b']->getMessages());
        $this->assertSame(['__idempotency_type' => 'unknown', 'x' => 1], $restored['c']);
    }
}
