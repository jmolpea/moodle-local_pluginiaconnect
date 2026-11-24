<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_pluginiaconnect;

use local_pluginiaconnect\local\guardrail\output_filter;
use local_pluginiaconnect\local\guardrail\text_sanitizer;
use local_pluginiaconnect\local\mcp\schema_validator;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\output_filter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\text_sanitizer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\schema_validator::class)]
/**
 * Output filter, text sanitizer and argument validator.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\guardrail\output_filter
 * @covers     \local_pluginiaconnect\local\guardrail\text_sanitizer
 * @covers     \local_pluginiaconnect\local\mcp\schema_validator
 */
final class mcp_guardrail_test extends \advanced_testcase {
    /**
     * Hidden content that must never reach the output.
     *
     * @return array
     */
    public static function hidden_provider(): array {
        return [
            'comment' => ['Visible<!-- SECRET -->'],
            'script' => ['Visible<script>SECRET</script>'],
            'hidden attribute' => ['Visible<p hidden>SECRET</p>'],
            'aria hidden' => ['Visible<span aria-hidden="true">SECRET</span>'],
            'display none' => ['Visible<span style="display:none">SECRET</span>'],
            'display none spaced' => ['Visible<span style="color:red; DISPLAY : none !important">SECRET</span>'],
            'visibility hidden' => ['Visible<div style="visibility:hidden">SECRET</div>'],
            'font size zero' => ['Visible<span style="font-size:0">SECRET</span>'],
            'font size zero px' => ['Visible<span style="font-size:0px">SECRET</span>'],
            'tiny font' => ['Visible<span style="font-size:1px">SECRET</span>'],
            'opacity' => ['Visible<span style="opacity:0">SECRET</span>'],
            'white on white' => ['Visible<span style="color:#fff;background-color:#FFFFFF">SECRET</span>'],
            'rgb same' => ['Visible<span style="color:rgb(1,2,3);background:rgb(1, 2, 3)">SECRET</span>'],
            'transparent' => ['Visible<span style="color:transparent">SECRET</span>'],
            'off screen' => ['Visible<div style="position:absolute;left:-9999px">SECRET</div>'],
            'text indent' => ['Visible<div style="text-indent:-9999px">SECRET</div>'],
            'zero box' => ['Visible<div style="width:0;height:0;overflow:hidden">SECRET</div>'],
            'clip' => ['Visible<span style="position:absolute;clip:rect(0,0,0,0)">SECRET</span>'],
            'bootstrap class' => ['Visible<span class="d-none">SECRET</span>'],
            'sr only' => ['Visible<span class="foo sr-only">SECRET</span>'],
            'moodle accesshide' => ['Visible<span class="accesshide">SECRET</span>'],
            'nested' => ['Visible<div style="display:none"><p><b>SECRET</b></p></div>'],
            'template' => ['Visible<template>SECRET</template>'],
            'textarea' => ['Visible<textarea>SECRET</textarea>'],
            'hidden input' => ['Visible<input type="hidden" value="SECRET">'],
            'svg' => ['Visible<svg><text>SECRET</text></svg>'],
        ];
    }

    /**
     * Hidden text is removed and visible text is kept.
     *
     * @dataProvider hidden_provider
     * @param string $html Rich text with hidden content.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hidden_provider')]
    public function test_hidden_text_is_removed(string $html): void {
        $text = text_sanitizer::to_plain($html);
        $this->assertStringNotContainsString('SECRET', $text);
        $this->assertStringContainsString('Visible', $text);
    }

    public function test_plain_text_conversion(): void {
        $text = text_sanitizer::to_plain('<p>One &amp; two</p><ul><li>A</li><li>B</li></ul><p>Ünïcode <b>bold</b></p>');
        $this->assertStringContainsString('One & two', $text);
        $this->assertStringContainsString('Ünïcode', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertSame('', text_sanitizer::to_plain('   '));
        $this->assertSame('just text', text_sanitizer::to_plain('just text'));
    }

    public function test_invisible_characters_are_removed(): void {
        $dirty = "ig\u{200B}no\u{200D}re\u{FEFF} \u{202E}all\u{2060}\u{00AD}\u{E0041}\u{E007F}\x07 ok";
        $this->assertSame('ignore all ok', text_sanitizer::to_plain($dirty));
        $this->assertSame('ab', text_sanitizer::clean_plain("a\u{200B}b"));
    }

    public function test_text_is_truncated_with_a_mark(): void {
        $text = text_sanitizer::to_plain('<p>' . str_repeat('a', 5000) . '</p>');
        $this->assertSame(text_sanitizer::MAX_FIELD_CHARS, \core_text::strlen($text));
        $this->assertStringEndsWith(text_sanitizer::TRUNCATION_MARK, $text);
        $this->assertSame('short', text_sanitizer::truncate('short'));
    }

    public function test_filter_keeps_only_declared_fields(): void {
        $this->resetAfterTest();
        $data = [
            'id' => 5,
            'name' => 'Course',
            'idnumber' => 'SECRET-ID',
            'password' => 'hunter2',
            'tags' => ['a', 'b', ['nested']],
            'owner' => ['name' => 'Ana', 'email' => 'ana@example.com', 'idnumber' => '12345678Z'],
            'items' => [
                ['title' => 'One', 'secret' => 'x', 'dates' => [['label' => 'Due', 'at' => 'now', 'ip' => '10.0.0.1']]],
                'not an object',
                ['title' => 'Two', 'token' => 'abc'],
            ],
            'undeclared' => ['deep' => ['secret' => 1]],
        ];
        $spec = [
            'id', 'name', 'tags', 'missing',
            'owner' => ['name'],
            'items' => ['*' => ['title', 'dates' => ['*' => ['label', 'at']]]],
        ];
        [$out, $truncated] = output_filter::apply($data, $spec);

        $this->assertFalse($truncated);
        $this->assertSame([
            'id' => 5,
            'name' => 'Course',
            'tags' => ['a', 'b'],
            'owner' => ['name' => 'Ana'],
            'items' => [
                ['title' => 'One', 'dates' => [['label' => 'Due', 'at' => 'now']]],
                ['title' => 'Two'],
            ],
        ], $out);
        $json = json_encode($out);
        foreach (['SECRET-ID', 'hunter2', 'ana@example.com', '12345678Z', '10.0.0.1', 'abc', 'deep'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_filter_drops_objects_declared_as_scalars(): void {
        $this->resetAfterTest();
        [$out] = output_filter::apply(['name' => ['first' => 'A', 'idnumber' => 'X']], ['name']);
        $this->assertSame(['name' => null], $out);
    }

    public function test_filter_truncates_long_text_and_large_responses(): void {
        $this->resetAfterTest();
        [$out, $truncated] = output_filter::apply(['text' => str_repeat('b', 6000)], ['text']);
        $this->assertTrue($truncated);
        $this->assertSame(text_sanitizer::MAX_FIELD_CHARS, \core_text::strlen($out['text']));

        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = ['title' => str_repeat('c', 300)];
        }
        [$out, $truncated] = output_filter::apply(['items' => $items], ['items' => ['*' => ['title']]], 5000);
        $this->assertTrue($truncated);
        $this->assertLessThanOrEqual(5000, strlen(json_encode($out)));
        $this->assertGreaterThan(5, count($out['items']));

        [$out, $truncated] = output_filter::apply(['text' => str_repeat('d', 3000)], ['text'], 100);
        $this->assertTrue($truncated);
        $this->assertSame([], $out);
    }

    public function test_filter_uses_the_site_limit(): void {
        $this->resetAfterTest();
        set_config('maxresponsechars', 2000, 'local_pluginiaconnect');
        $items = array_fill(0, 50, ['title' => str_repeat('e', 200)]);
        [$out, $truncated] = output_filter::apply(['items' => $items], ['items' => ['*' => ['title']]]);
        $this->assertTrue($truncated);
        $this->assertLessThanOrEqual(2000, strlen(json_encode($out)));
    }

    /**
     * Valid and invalid arguments.
     *
     * @return array
     */
    public static function validator_provider(): array {
        return [
            'empty ok' => [[], null],
            'all ok' => [['n' => 5, 'f' => 1.5, 's' => 'ab', 'b' => true, 'e' => 'x', 'l' => [1, 2]], null],
            'unknown property' => [['sql' => 'select 1'], "Unknown argument 'sql'."],
            'string for integer' => [['n' => '5'], "'n' must be an integer."],
            'float for integer' => [['n' => 5.0], "'n' must be an integer."],
            'below minimum' => [['n' => 0], "'n' must be at least 1."],
            'above maximum' => [['n' => 11], "'n' must be at most 10."],
            'number' => [['f' => 'x'], "'f' must be a number."],
            'number range' => [['f' => 99.5], "'f' must be at most 10."],
            'string type' => [['s' => 5], "'s' must be a string."],
            'string short' => [['s' => 'a'], "'s' must have at least 2 characters."],
            'string long' => [['s' => 'abcdef'], "'s' must have at most 5 characters."],
            'multibyte length' => [['s' => 'ñññññ'], null],
            'boolean' => [['b' => 1], "'b' must be true or false."],
            'enum' => [['e' => 'z'], "'e' must be one of: x, y."],
            'list type' => [['l' => ['a' => 1]], "'l' must be a list."],
            'list items' => [['l' => [1, 'a']], "Each item of 'l' must be an integer."],
            'list max' => [['l' => [1, 2, 3, 4]], "'l' must have at most 3 items."],
            'list unique' => [['l' => [1, 1]], "'l' must not contain repeated items."],
            'null value' => [['n' => null], "'n' must be an integer."],
            'object for scalar' => [['s' => ['a' => 'b']], "'s' must be a string."],
        ];
    }

    /**
     * The validator accepts exactly what the schema allows and coerces nothing.
     *
     * @dataProvider validator_provider
     * @param array $args Arguments.
     * @param string|null $expected Expected problem.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validator_provider')]
    public function test_validator(array $args, ?string $expected): void {
        $properties = [
            'n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            'f' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10],
            's' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 5],
            'b' => ['type' => 'boolean'],
            'e' => ['type' => 'string', 'enum' => ['x', 'y']],
            'l' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'uniqueItems' => true, 'items' => ['type' => 'integer']],
        ];
        $this->assertSame($expected, schema_validator::validate($args, $properties, []));
    }

    public function test_validator_required_shape_and_unknown_schema(): void {
        $properties = ['id' => ['type' => 'integer'], 'weird' => ['type' => 'object']];
        $this->assertSame("'id' is required.", schema_validator::validate([], $properties, ['id']));
        $this->assertSame('Arguments must be a JSON object.', schema_validator::validate([1, 2], $properties, []));
        $this->assertSame('Arguments must be a JSON object.', schema_validator::validate('x', $properties, []));
        $this->assertSame("'weird' cannot be validated.", schema_validator::validate(['weird' => []], $properties, []));
        $this->assertSame("Unknown argument 'a??b'.", schema_validator::validate(['a<>b' => 1], $properties, []));
    }
}
