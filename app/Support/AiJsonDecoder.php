<?php

namespace App\Support;

use JsonException;

final class AiJsonDecoder
{
    /** @return array<string, mixed> */
    public static function decodeObject(string $text): array
    {
        $candidate = self::candidate($text);
        $escaped = self::escapeRawControlCharacters($candidate);
        $attempts = [
            $candidate,
            $escaped,
            self::flattenRawControlCharacters($candidate),
            self::flattenRawControlCharacters($escaped),
        ];
        $fragment = self::objectFragment($candidate);
        if ($fragment !== null) {
            $escapedFragment = self::escapeRawControlCharacters($fragment);
            $attempts[] = $fragment;
            $attempts[] = $escapedFragment;
            $attempts[] = self::flattenRawControlCharacters($fragment);
            $attempts[] = self::flattenRawControlCharacters($escapedFragment);
        }

        $lastException = null;
        foreach (array_unique($attempts) as $attempt) {
            try {
                $decoded = json_decode(
                    $attempt,
                    true,
                    512,
                    JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
                );
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (JsonException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new JsonException('AI response JSON must be an object or array.');
    }

    private static function candidate(string $text): string
    {
        $candidate = trim($text);
        if (str_starts_with($candidate, "\xEF\xBB\xBF")) {
            $candidate = substr($candidate, 3);
        }
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/is', $candidate, $matches) === 1) {
            $candidate = trim($matches[1]);
        }

        return $candidate;
    }

    private static function escapeRawControlCharacters(string $json): string
    {
        $result = '';
        $insideString = false;
        $escaped = false;
        $length = strlen($json);

        for ($index = 0; $index < $length; $index++) {
            $character = $json[$index];
            $code = ord($character);

            if (! $insideString) {
                if ($character === '"') {
                    $insideString = true;
                }
                $result .= $code < 0x20 && ! in_array($character, ["\t", "\n", "\r"], true)
                    ? ''
                    : $character;

                continue;
            }

            if ($escaped) {
                // A model may emit a backslash followed by a literal control
                // character (most commonly a line break). The backslash was
                // already appended, so complete a valid JSON escape here.
                $result .= $code < 0x20
                    ? match ($character) {
                        "\n" => 'n',
                        "\r" => 'r',
                        "\t" => 't',
                        "\b" => 'b',
                        "\f" => 'f',
                        default => sprintf('u%04x', $code),
                    }
                : $character;
                $escaped = false;

                continue;
            }
            if ($character === '\\') {
                $result .= $character;
                $escaped = true;

                continue;
            }
            if ($character === '"') {
                $result .= $character;
                $insideString = false;

                continue;
            }
            if ($code < 0x20) {
                $result .= match ($character) {
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    default => sprintf('\\u%04x', $code),
                };

                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    /**
     * Last-resort recovery for malformed model output. Replacing raw control
     * bytes everywhere is intentionally lossy, but keeps all semantic text and
     * guarantees that json_decode cannot encounter an unescaped control byte.
     */
    private static function flattenRawControlCharacters(string $json): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', ' ', $json) ?? $json;
    }

    private static function objectFragment(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($text, $start, $end - $start + 1);
    }
}
