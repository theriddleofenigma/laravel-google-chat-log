<?php

declare(strict_types=1);

namespace Enigma\Support;

/**
 * Keeps a Google Chat payload under the 32,000 byte message limit.
 *
 * The budget only kicks in when the encoded payload is over budget, so
 * payloads that fit are returned untouched (byte-identical). Fields are
 * trimmed group by group, in priority order, largest field first.
 */
class PayloadBudget
{
    /**
     * Default budget in bytes, leaving headroom below Google's 32,000 limit.
     */
    public const DEFAULT_BYTES = 30000;

    /**
     * Appended to every trimmed field.
     */
    public const MARKER = ' [truncated]';

    public function __construct(protected int $bytes = self::DEFAULT_BYTES) {}

    /**
     * The size of the payload as sent on the wire (Guzzle's json_encode flags).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function size(array $payload): int
    {
        $json = json_encode($payload);

        return $json === false ? 0 : strlen($json);
    }

    /**
     * Trim the payload until it fits the budget.
     *
     * Groups are trimmed in priority order, in two passes. The first pass
     * never cuts a field below its group's floor, so a huge low-priority field
     * (e.g. the header title) can not wipe out a higher-priority one entirely.
     * The second pass removes the floors and only runs when still over budget.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<int, string>>  $groups  Dot paths of string fields,
     *                                                  grouped in trimming priority order.
     * @param  array<int, int>  $floors  Minimum encoded bytes per field, per group.
     * @return array<string, mixed>
     */
    public function apply(array $payload, array $groups, array $floors = []): array
    {
        if (self::size($payload) <= $this->bytes) {
            return $payload;
        }

        $payload = $this->trim($payload, $groups, $floors);

        return $this->trim($payload, $groups, []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<int, string>>  $groups
     * @param  array<int, int>  $floors
     * @return array<string, mixed>
     */
    protected function trim(array $payload, array $groups, array $floors): array
    {
        $size = self::size($payload);

        foreach ($groups as $index => $paths) {
            $floor = $floors[$index] ?? 0;
            $candidates = array_values(array_filter(
                $paths,
                fn (string $path) => is_string(data_get($payload, $path))
                    && self::encodedLength((string) data_get($payload, $path)) > $floor
            ));

            while ($size > $this->bytes && $candidates !== []) {
                // Trim the largest remaining field in this group first.
                usort($candidates, fn ($a, $b) => self::encodedLength((string) data_get($payload, $b))
                    <=> self::encodedLength((string) data_get($payload, $a)));

                $path = array_shift($candidates);
                $value = (string) data_get($payload, $path);
                $target = max($floor, self::encodedLength($value) - ($size - $this->bytes));

                data_set($payload, $path, self::truncate($value, $target));
                $size = self::size($payload);
            }

            if ($size <= $this->bytes) {
                break;
            }
        }

        return $payload;
    }

    /**
     * Cut a string so its JSON encoded form (marker included) is at most
     * $maxBytes long. Multibyte characters are never split.
     */
    public static function truncate(string $value, int $maxBytes): string
    {
        if (self::encodedLength($value) <= $maxBytes) {
            return $value;
        }

        $markerLength = self::encodedLength(self::MARKER);

        if ($maxBytes <= $markerLength) {
            return trim(self::MARKER);
        }

        // Binary search for the longest prefix (in characters) that fits.
        $low = 0;
        $high = mb_strlen($value);

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);

            if (self::encodedLength(mb_substr($value, 0, $mid)) + $markerLength <= $maxBytes) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return mb_substr($value, 0, $low).self::MARKER;
    }

    /**
     * Length of a string once JSON encoded, without the surrounding quotes.
     */
    public static function encodedLength(string $value): int
    {
        $json = json_encode($value);

        return $json === false ? strlen($value) : strlen($json) - 2;
    }
}
