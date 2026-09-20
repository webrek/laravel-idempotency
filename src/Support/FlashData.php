<?php

namespace Webrek\Idempotency\Support;

use Illuminate\Contracts\Support\MessageBag as MessageBagContract;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * Converts session flash data to and from a plain-array form that survives
 * any cache store. Laravel refuses to unserialise objects from the cache
 * unless they are allowlisted (`cache.serializable_classes`, false by
 * default), so validation error bags are flattened to arrays on the way in
 * and rebuilt on the way out. Scalars and plain arrays pass through; any
 * other object is dropped rather than risk an incomplete class on replay.
 */
final class FlashData
{
    private const MARKER = '__idempotency_type';

    /**
     * @param  array<string, mixed>  $flash
     * @return array<string, mixed>
     */
    public static function pack(array $flash): array
    {
        $packed = [];

        foreach ($flash as $key => $value) {
            if ($value instanceof ViewErrorBag) {
                $bags = [];

                foreach ($value->getBags() as $name => $bag) {
                    $bags[(string) $name] = $bag->getMessages();
                }

                $packed[$key] = [self::MARKER => 'view_error_bag', 'bags' => $bags];

                continue;
            }

            if ($value instanceof MessageBagContract) {
                $packed[$key] = [self::MARKER => 'message_bag', 'messages' => $value->getMessages()];

                continue;
            }

            if (self::isPlain($value)) {
                $packed[$key] = $value;
            }
        }

        return $packed;
    }

    /**
     * @param  array<string, mixed>  $packed
     * @return array<string, mixed>
     */
    public static function unpack(array $packed): array
    {
        $flash = [];

        foreach ($packed as $key => $value) {
            $flash[$key] = self::restore($value);
        }

        return $flash;
    }

    private static function restore(mixed $value): mixed
    {
        if (! is_array($value) || ! isset($value[self::MARKER])) {
            return $value;
        }

        if ($value[self::MARKER] === 'view_error_bag') {
            $errors = new ViewErrorBag;

            /** @var array<string, array<string, list<string>>> $bags */
            $bags = is_array($value['bags'] ?? null) ? $value['bags'] : [];

            foreach ($bags as $name => $messages) {
                $errors->put($name, new MessageBag($messages));
            }

            return $errors;
        }

        if ($value[self::MARKER] === 'message_bag') {
            /** @var array<string, list<string>> $messages */
            $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];

            return new MessageBag($messages);
        }

        return $value;
    }

    private static function isPlain(mixed $value): bool
    {
        if (is_scalar($value) || $value === null) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::isPlain($item)) {
                return false;
            }
        }

        return true;
    }
}
