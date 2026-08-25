<?php

namespace Tests\Unit;

use App\Support\AiJsonDecoder;
use JsonException;
use PHPUnit\Framework\TestCase;

final class AiJsonDecoderTest extends TestCase
{
    public function test_it_repairs_raw_control_characters_inside_json_strings(): void
    {
        $decoded = AiJsonDecoder::decodeObject("{\"summary\":\"Dòng một\nDòng hai\tNội dung\x00\"}");

        $this->assertSame("Dòng một\nDòng hai\tNội dung\x00", $decoded['summary']);
    }

    public function test_it_accepts_a_json_markdown_fence(): void
    {
        $decoded = AiJsonDecoder::decodeObject("```json\n{\"risk_level\":\"review\"}\n```");

        $this->assertSame('review', $decoded['risk_level']);
    }

    public function test_it_repairs_a_backslash_followed_by_a_raw_line_break(): void
    {
        $decoded = AiJsonDecoder::decodeObject("{\"summary\":\"Dòng một\\\nDòng hai\"}");

        $this->assertSame("Dòng một\nDòng hai", $decoded['summary']);
    }

    public function test_it_falls_back_to_an_embedded_json_object_with_raw_controls(): void
    {
        $decoded = AiJsonDecoder::decodeObject("Kết quả:\n{\"summary\":\"Dòng một\x00Dòng hai\"}\nHoàn tất");

        $this->assertSame("Dòng một\x00Dòng hai", $decoded['summary']);
    }

    public function test_it_does_not_hide_a_truncated_response(): void
    {
        $this->expectException(JsonException::class);

        AiJsonDecoder::decodeObject('{"summary":"unfinished');
    }
}
