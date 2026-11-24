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

use local_pluginiaconnect\local\guardrail\text_sanitizer;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\text_sanitizer::class)]
/**
 * Hidden-text removal: every bypass found by the independent security review (finding M3),
 * and the visible content that must survive.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\guardrail\text_sanitizer
 */
final class text_sanitizer_test extends \advanced_testcase {
    /**
     * Markup in which the word SECRET cannot be seen by a reader.
     *
     * @return array
     */
    public static function hidden_provider(): array {
        $style = static fn(string $css): array => ['Visible<span style="' . $css . '">SECRET</span>'];
        return [
            // Colour.
            'white, no background declared' => $style('color:#fff'),
            'white name' => $style('color:white'),
            'white upper case' => $style('COLOR:#FFFFFF'),
            'font element' => ['Visible<font color="white">SECRET</font>'],
            'font element hex without hash' => ['Visible<font color="ffffff">SECRET</font>'],
            'near white on white' => $style('color:#fffffe;background:#fff'),
            'near white, no background' => $style('color:#fdfdfd'),
            'rgb space syntax' => $style('color:rgb(255 255 255)'),
            'rgb percent' => $style('color:rgb(100%, 100%, 100%)'),
            'rgba opaque white' => $style('color:rgba(255,255,255,1)'),
            'rgba transparent' => $style('color:rgba(0,0,0,0.02)'),
            'rgb slash alpha' => $style('color:rgb(0 0 0 / 3%)'),
            'hex with alpha' => $style('color:#00000005'),
            'hsl white' => $style('color:hsl(0, 0%, 100%)'),
            'hsl space syntax' => $style('color:hsl(120deg 20% 99%)'),
            'light named colour' => $style('color:ivory'),
            'unknown colour name' => $style('color:papayawhipzz'),
            'colour function' => $style('color:var(--bs-white)'),
            'text fill colour' => $style('-webkit-text-fill-color:transparent'),
            'background on a parent' => [
                'Visible<div style="background:#123456"><p><span style="color:#123457">SECRET</span></p></div>',
            ],
            'dark background, default text' => ['Visible<div style="background-color:#212529">SECRET</div>'],
            'background image' => $style('background:url(x.png);color:#000'),
            'background gradient' => $style('background:linear-gradient(#fff,#fff)'),
            'bootstrap text-white' => ['Visible<span class="text-white">SECRET</span>'],
            'bootstrap text-light' => ['Visible<span class="text-light">SECRET</span>'],
            'bootstrap same text and background' => ['Visible<div class="bg-dark"><span class="text-dark">SECRET</span></div>'],
            'bgcolor attribute' => [
                'Visible<table><tr><td bgcolor="#000000"><font color="#010101">SECRET</font></td></tr></table>',
            ],
            // Size and opacity.
            'one point font' => $style('font-size:1pt'),
            'tiny em font' => $style('font-size:0.01em'),
            'exponent font' => $style('font-size:1e-5px'),
            'percent font' => $style('font-size:1%'),
            'rem font' => $style('font-size:.1rem'),
            'calc font' => $style('font-size:calc(1px - 1px)'),
            'font shorthand' => $style('font:0/0 a'),
            'font shorthand tiny' => $style('font:italic 1px/1 serif'),
            'low opacity' => $style('opacity:0.06'),
            'percent opacity' => $style('opacity:0%'),
            'opacity expression' => $style('opacity:calc(0)'),
            'filter opacity' => $style('filter:opacity(0)'),
            'zoom' => $style('zoom:0.01'),
            'scale x' => $style('display:inline-block;transform:scaleX(0)'),
            'scale property' => $style('scale:0'),
            'line height zero' => $style('line-height:0;overflow:hidden'),
            // Position and clipping.
            'viewport offset' => $style('position:absolute;left:-100vw'),
            'percent offset' => $style('position:relative;left:-200%'),
            'translate' => $style('display:block;transform:translateX(-9999px)'),
            'inches offset' => $style('position:absolute;top:-99in'),
            'small negative offset' => $style('position:absolute;top:-20px'),
            'far positive offset' => $style('position:absolute;left:5000px'),
            'negative text indent' => $style('display:block;text-indent:-10em'),
            'huge text indent' => $style('display:block;text-indent:9999px;overflow:hidden;white-space:nowrap'),
            'big negative margin' => $style('margin-left:-3000px'),
            'one pixel box' => $style('display:block;height:1px;overflow:hidden'),
            'one pixel wide box' => $style('display:inline-block;width:1px;overflow:hidden;white-space:nowrap'),
            'zero max height' => $style('display:block;max-height:0'),
            'clip path' => $style('clip-path:circle(0)'),
            'clip path inset' => $style('clip-path:inset(50%)'),
            'clip rect' => $style('position:absolute;clip:rect(1px,1px,1px,1px)'),
            'mask' => $style('mask-image:linear-gradient(transparent,transparent)'),
            'content visibility' => $style('content-visibility:hidden'),
            'fixed position' => $style('position:fixed;top:0'),
            'negative z-index' => $style('position:relative;z-index:-1'),
            // Classes and attributes.
            'opacity-0 class' => ['Visible<span class="opacity-0">SECRET</span>'],
            'responsive d-none' => ['Visible<span class="d-sm-none">SECRET</span>'],
            'print d-none' => ['Visible<span class="d-print-none d-lg-none">SECRET</span>'],
            'modal' => ['Visible<div class="modal"><div class="modal-body">SECRET</div></div>'],
            'tab pane' => ['Visible<div class="tab-pane">SECRET</div>'],
            'fade' => ['Visible<div class="fade">SECRET</div>'],
            'collapse' => ['Visible<div class="collapse">SECRET</div>'],
            'invisible' => ['Visible<span class="invisible">SECRET</span>'],
            'visually hidden' => ['Visible<span class="visually-hidden">SECRET</span>'],
            'dropdown menu' => ['Visible<div class="dropdown-menu">SECRET</div>'],
            'closed details' => ['Visible<details><summary>More</summary><p>SECRET</p></details>'],
            'dialog' => ['Visible<dialog>SECRET</dialog>'],
            'hidden attribute with value' => ['Visible<p hidden="until-found">SECRET</p>'],
            'aria hidden upper case' => ['Visible<span ARIA-HIDDEN="TRUE">SECRET</span>'],
            // Obfuscated styles.
            'css hex escape' => $style('d\\69splay:none'),
            'css hex escape with space' => $style('d\\000069 splay:none'),
            'css character escape' => $style('displa\\y:none'),
            'css escape in the value' => $style('display:n\\6fne'),
            'entity in the style' => ['Visible<span style="display&#58;none">SECRET</span>'],
            'entity escape' => ['Visible<span style="d&#x5c;69splay:none">SECRET</span>'],
            // Verification pass of the second review: cascade, unrendered elements, classes, thresholds, parser.
            'important first' => $style('display:none !important;display:block'),
            'display then unknown' => $style('display:none;display:foo'),
            'visibility then unknown' => $style('visibility:hidden;visibility:foo'),
            'margin then unknown' => $style('margin-left:-9999px;margin-left:foo'),
            'colour then unknown' => $style('color:#fff;color:#000 !ie'),
            'white then black' => $style('color:#fff !important;color:#000'),
            'ruby fallback' => ['Visible<ruby>kanji<rp>SECRET</rp><rt>kana</rt></ruby>'],
            'noembed' => ['Visible<noembed>SECRET</noembed>'],
            'meter content' => ['Visible<meter value="1">SECRET</meter>'],
            'progress content' => ['Visible<progress>SECRET</progress>'],
            'marquee without height' => ['Visible<marquee height="0">SECRET</marquee>'],
            'text node in closed details' => ['Visible<details><summary>More</summary>SECRET</details>'],
            'shadow root host' => ['Visible<div><template shadowrootmode="open">x</template>SECRET</div>'],
            'image alt' => ['Visible<img src="x.png" alt="SECRET">'],
            'image title' => ['Visible<img src="x.png" title="SECRET">'],
            'invalid feedback' => ['Visible<div class="invalid-feedback">SECRET</div>'],
            'carousel item' => ['Visible<div class="carousel-item">SECRET</div>'],
            'hidden if js' => ['Visible<div class="hiddenifjs">SECRET</div>'],
            'hidden left to right' => ['Visible<div class="dir-ltr-hide">SECRET</div>'],
            'custom control input' => ['Visible<span class="custom-control-input">SECRET</span>'],
            'two pixel box' => $style('display:block;height:2px;overflow:hidden'),
            'three pixel font' => $style('font-size:3px'),
            'faint opacity' => $style('opacity:0.1'),
            'letter spacing' => $style('letter-spacing:-1em'),
            'word spacing' => $style('word-spacing:-20px'),
            'bogus end tag' => ['Visible</ SECRET >'],
            'bogus end tag unterminated' => ['Visible</!SECRET'],
            'comment in the style' => $style('dis/**/play:none'),
            'comment in the value' => $style('display:/* x */none'),
            'zero width in the property' => $style("dis\u{200B}play:none"),
            'important and case' => $style('Display : NONE ! important'),
            'second declaration wins' => $style('display:block;display:none'),
            'display list-item none' => $style('display:none flow'),
            'unbalanced style' => $style('color:red;;;display:none'),
            // Nesting.
            'hidden ancestor' => ['Visible<div style="opacity:0"><p style="opacity:1;color:#000">SECRET</p></div>'],
            'comment inside a tag soup' => ['Visible<p>a<!-- SECRET --></p>'],
            'conditional comment' => ['Visible<!--[if mso]>SECRET<![endif]-->'],
            'cdata' => ['Visible<![CDATA[SECRET]]>'],
            'noscript' => ['Visible<noscript>SECRET</noscript>'],
            'object fallback' => ['Visible<object data="x">SECRET</object>'],
            'title element' => ['Visible<title>SECRET</title>'],
            // Local penetration test, H-1: hiding that builds up along the ancestors.
            'nested percent font' => [
                'Visible' . str_repeat('<span style="font-size:40%">', 5) . 'SECRET' . str_repeat('</span>', 5),
            ],
            'nested em font' => [
                'Visible' . str_repeat('<span style="font-size:.5em">', 3) . 'SECRET' . str_repeat('</span>', 3),
            ],
            'nested smaller keyword' => [
                'Visible' . str_repeat('<span style="font-size:smaller">', 6) . 'SECRET' . str_repeat('</span>', 6),
            ],
            'nested small elements' => ['Visible' . str_repeat('<small>', 8) . 'SECRET' . str_repeat('</small>', 8)],
            'nested small, sub and sup' => ['Visible<small><sub><sup><small><sub>SECRET</sub></small></sup></sub></small>'],
            'nested small class' => [
                'Visible' . str_repeat('<span class="small">', 6) . 'SECRET' . str_repeat('</span>', 6),
            ],
            'small font inside a font shorthand' => [
                'Visible<span style="font:50% serif"><span style="font:italic 50%/1.5 serif">SECRET</span></span>',
            ],
            'nested zoom' => ['Visible' . str_repeat('<span style="zoom:.5">', 3) . 'SECRET' . str_repeat('</span>', 3)],
            'nested opacity' => [
                'Visible' . str_repeat('<span style="opacity:.4">', 4) . 'SECRET' . str_repeat('</span>', 4),
            ],
            'two levels of opacity' => ['Visible<div style="opacity:.4"><span style="opacity:.4">SECRET</span></div>'],
            'nested opacity classes' => [
                'Visible' . str_repeat('<span class="opacity-25">', 3) . 'SECRET' . str_repeat('</span>', 3),
            ],
            'opacity class under an opacity style' => [
                'Visible<div style="opacity:.5"><span class="opacity-25">SECRET</span></div>',
            ],
            'opacity that a child cannot undo' => [
                'Visible<div style="opacity:.3"><div style="opacity:.5"><b style="opacity:1">SECRET</b></div></div>',
            ],
            // H-1: backgrounds, blending and margins the cleaner did not measure.
            'gradient as background image' => $style('color:#123456;background-image:linear-gradient(#123456,#123456)'),
            'background image property' => $style('background-image:url(x.png)'),
            'background attribute' => ['Visible<table><tr><td background="x.png">SECRET</td></tr></table>'],
            'blend mode' => $style('color:black;mix-blend-mode:lighten'),
            'blend mode difference' => $style('mix-blend-mode:difference'),
            'text security' => $style('-webkit-text-security:disc'),
            'float with a percent margin' => ['Visible<div style="float:left;margin-left:-300%">SECRET</div>'],
            'percent margin' => $style('display:block;margin-left:-150%'),
            'percent margin shorthand' => $style('display:block;margin:0 0 0 -200%'),
            'far positive percent margin' => $style('display:block;margin-left:400%'),
            'margin that cannot be measured' => $style('display:block;margin-left:calc(-100vw - 10px)'),
            'margin from a variable' => $style('display:block;margin-inline-start:var(--x, -9999px)'),
            // H-1: classes of YUI, of the Moodle dialogues and of Bootstrap 5.
            'yui overlay hidden' => ['Visible<span class="yui3-overlay-hidden">SECRET</span>'],
            'yui panel hidden' => ['Visible<span class="yui3-panel-hidden">SECRET</span>'],
            'dialogue hidden' => [
                'Visible<div class="moodle-dialogue-base"><span class="moodle-dialogue-hidden">SECRET</span></div>',
            ],
            'any class ending in hidden' => ['Visible<span class="mytheme_hidden">SECRET</span>'],
            'placeholder' => ['Visible<span class="placeholder">SECRET</span>'],
            'placeholder upper case' => ['Visible<span class="PlaceHolder col-6">SECRET</span>'],
            'link light' => ['Visible<span class="link-light">SECRET</span>'],
            'link opacity' => ['Visible<a class="link-primary link-opacity-10" href="#">SECRET</a>'],
            'outline light button' => ['Visible<span class="btn btn-outline-light">SECRET</span>'],
            'dark text on a paired dark background' => [
                'Visible<div class="text-bg-dark"><span class="text-dark">SECRET</span></div>',
            ],
            'button with its own colour as text' => ['Visible<span class="btn btn-dark text-dark">SECRET</span>'],
            'clipping class on a tiny box' => ['Visible<div class="overflow-hidden" style="height:2px">SECRET</div>'],
        ];
    }

    /**
     * Hidden text is removed and the visible text next to it is kept.
     *
     * @dataProvider hidden_provider
     * @param string $html Rich text with hidden content.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hidden_provider')]
    public function test_hidden_text_is_removed(string $html): void {
        $text = text_sanitizer::to_plain($html);
        $this->assertStringNotContainsString('SECRET', $text, $html);
        $this->assertStringContainsString('Visible', $text, $html);
    }

    /**
     * Markup whose text a reader does see.
     *
     * @return array
     */
    public static function visible_provider(): array {
        return [
            'plain paragraph' => ['<p>SHOWN text</p>'],
            'bold and links' => ['<p><strong>SHOWN</strong> <a href="https://example.com/x">link</a></p>'],
            'red text' => ['<span style="color:red">SHOWN</span>'],
            'dark text on a light box' => ['<div style="background-color:#fff3cd;color:#664d03">SHOWN</div>'],
            'white text on a dark box' => ['<div style="background:#0f6cbf;color:#fff">SHOWN</div>'],
            'white text on a dark box, shorthand' => ['<div style="background:#000 none repeat;color:white">SHOWN</div>'],
            'white text inside a dark parent' => [
                '<div style="background-color:rgb(33, 37, 41)"><p style="color:#ffffff">SHOWN</p></div>',
            ],
            'bootstrap dark box' => ['<div class="bg-dark text-white p-3">SHOWN</div>'],
            'bootstrap alert' => ['<div class="alert alert-warning">SHOWN</div>'],
            'bootstrap primary' => ['<span class="badge bg-primary text-white">SHOWN</span>'],
            'muted text' => ['<small class="text-muted">SHOWN</small>'],
            'small but readable' => ['<span style="font-size:11px">SHOWN</span>'],
            'font keyword' => ['<span style="font-size:small">SHOWN</span>'],
            'em size' => ['<span style="font-size:0.8em">SHOWN</span>'],
            'percent size' => ['<span style="font-size:90%">SHOWN</span>'],
            'half transparent' => ['<span style="opacity:0.6">SHOWN</span>'],
            'floated image beside the text' => ['<img src="x.png" alt="x" style="float:right;margin:0 0 1em 1em"><p>SHOWN</p>'],
            'moved text without paint' => ['<p>SHOWN</p><span style="position:relative;top:2px">note</span>'],
            'loose text in open details' => ['<details open><summary>More</summary>SHOWN</details>'],
            'active carousel item' => ['<div class="carousel-item active">SHOWN</div>'],
            'repeated harmless declarations' => ['<span style="color:#000;color:#111;display:inline;display:block">SHOWN</span>'],
            'tight letter spacing' => ['<span style="letter-spacing:-0.5px">SHOWN</span>'],
            'short clipped box with room for a line' => ['<div style="height:1.5em;overflow:hidden">SHOWN</div>'],
            'comparison in the text' => ['<p>SHOWN when a &lt;/ b and 2 < 3</p>'],
            'centred with width' => ['<div style="width:80%;margin:0 auto;text-align:center">SHOWN</div>'],
            'padding and border' => ['<div style="padding:1rem;border:1px solid #ccc;border-radius:4px">SHOWN</div>'],
            'table cell' => ['<table><tr><td style="width:50%;vertical-align:top">SHOWN</td></tr></table>'],
            'float image caption' => ['<div style="float:left;max-width:300px">SHOWN</div>'],
            'collapse shown' => ['<div class="collapse show">SHOWN</div>'],
            'active tab' => ['<div class="tab-pane fade show active">SHOWN</div>'],
            'open details' => ['<details open><summary>More</summary><p>SHOWN</p></details>'],
            'summary of closed details' => ['<details><summary>SHOWN</summary><p>later</p></details>'],
            'display block' => ['<span style="display:inline-block">SHOWN</span>'],
            'inherit colour' => ['<span style="color:inherit">SHOWN</span>'],
            'hsl dark' => ['<span style="color:hsl(210, 80%, 30%)">SHOWN</span>'],
            'rgb dark' => ['<span style="color:rgb(10 20 30)">SHOWN</span>'],
            'relative position zero' => ['<div style="position:relative;top:0;left:0">SHOWN</div>'],
            'small positive indent' => ['<p style="text-indent:2em">SHOWN</p>'],
            'small negative margin' => ['<div class="row" style="margin-left:-15px">SHOWN</div>'],
            'accented text' => ['<p>SHOWN añadido — «ñ» 日本語</p>'],
            // Local penetration test, H-1: ordinary text must not be lost to the reckoning along ancestors.
            'one small element' => ['<p>Note: <small>SHOWN</small></p>'],
            'small inside small' => ['<small>The <small>SHOWN</small> part</small>'],
            'subscript and superscript' => ['<p>H<sub>2</sub>O and x<sup>2</sup> SHOWN <sub>SHOWN</sub></p>'],
            'small with a percent size' => ['<small><span style="font-size:90%">SHOWN</span></small>'],
            'percent inside percent' => ['<div style="font-size:90%"><span style="font-size:80%">SHOWN</span></div>'],
            'small inside a larger box' => ['<div style="font-size:1.5em"><span style="font-size:60%">SHOWN</span></div>'],
            'font shorthand with a weight and a line height' => ['<span style="font:bold 12px/16px Arial">SHOWN</span>'],
            'half transparent twice' => ['<div style="opacity:.6"><span style="opacity:.6">SHOWN</span></div>'],
            'one opacity class' => ['<span class="opacity-75">SHOWN</span>'],
            'opacity class once inside half transparent' => ['<div style="opacity:.9"><span class="opacity-50">SHOWN</span></div>'],
            'no background image' => ['<div style="background-image:none">SHOWN</div>'],
            'normal blend mode' => ['<span style="mix-blend-mode:normal">SHOWN</span>'],
            'small percent margins' => ['<div style="margin:0 5%">SHOWN</div>'],
            'automatic margins' => ['<div style="margin-left:auto;margin-right:auto;width:60%">SHOWN</div>'],
            'clipping class with room for the text' => ['<div class="overflow-hidden">SHOWN</div>'],
            'scrolling box' => ['<div class="overflow-auto" style="max-height:300px">SHOWN</div>'],
            'primary button' => ['<a class="btn btn-primary" href="https://example.com/x">SHOWN</a>'],
            'secondary button' => ['<a class="btn btn-secondary" href="https://example.com/x">SHOWN</a>'],
            'outline button' => ['<a class="btn btn-outline-primary" href="https://example.com/x">SHOWN</a>'],
            'paired badge' => ['<span class="badge text-bg-warning">SHOWN</span>'],
            'primary link' => ['<a class="link-primary" href="https://example.com/x">SHOWN</a>'],
        ];
    }

    /**
     * Normal visible content survives, including coloured text on a declared contrasting background.
     *
     * @dataProvider visible_provider
     * @param string $html Rich text.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('visible_provider')]
    public function test_visible_text_is_kept(string $html): void {
        $this->assertStringContainsString('SHOWN', text_sanitizer::to_plain($html), $html);
    }

    /**
     * Invisible characters added by the review: separators, object replacement, blank braille,
     * musical controls, bidirectional controls and tag characters.
     */
    public function test_invisible_characters(): void {
        $dirty = "re\u{2028}ad\u{2029} \u{FFFC}the\u{2800}\u{1D173}\u{1D17A} "
            . "\u{202E}file\u{2066}\u{2069}\u{E0001}\u{E0049}\u{E007F}";
        $clean = text_sanitizer::clean_plain($dirty);
        $this->assertSame('re ad the file', preg_replace('/ +/', ' ', $clean));
        foreach (["\u{2028}", "\u{2029}", "\u{FFFC}", "\u{2800}", "\u{1D173}", "\u{202E}", "\u{2066}", "\u{E0049}"] as $char) {
            $this->assertStringNotContainsString($char, $clean);
        }
        // Tag characters spelling a hidden instruction vanish entirely.
        $tagged = 'Hello' . implode('', array_map(static fn($c) => mb_chr(0xE0000 + ord($c)), str_split('ignore')));
        $this->assertSame('Hello', text_sanitizer::to_plain($tagged));
        // Ordinary text, accents and emoji are untouched.
        $this->assertSame('Añade 2 × 3 = 6 ✅', text_sanitizer::clean_plain('Añade 2 × 3 = 6 ✅'));
    }

    /**
     * Hostile input does not break the sanitiser.
     */
    public function test_robustness(): void {
        $deep = str_repeat('<div>', 400) . 'DEEP' . str_repeat('</div>', 400);
        $this->assertStringNotContainsString('DEEP', text_sanitizer::to_plain($deep));
        $long = '<span style="' . str_repeat('color:red;', 2000) . '">LONGSTYLE</span>ok';
        $this->assertStringNotContainsString('LONGSTYLE', text_sanitizer::to_plain($long));
        $this->assertSame('', text_sanitizer::strip_hidden(''));
        $this->assertSame('a < b', text_sanitizer::to_plain('a &lt; b'));
        $this->assertStringNotContainsString('<', text_sanitizer::to_plain('<p>x</p><scr<script>ipt>alert(1)</script>'));
    }

    /**
     * Markup that can lay one part of the text over another: nothing of the text is returned.
     *
     * @return array
     */
    public static function overlay_provider(): array {
        return [
            'absolute box with a background' => [
                '<p>SECRET</p><div style="position:absolute;top:0;left:0;background:#fff">Cover</div>',
            ],
            'absolute image' => ['<p>SECRET</p><img src="x.png" style="position:absolute;top:0">'],
            'negative margin with a background' => ['<p>SECRET</p><p style="margin-top:-1.5em;background:white">Cover</p>'],
            'relative shift with a class' => ['<p>SECRET</p><div class="card" style="position:relative;top:-20px">Cover</div>'],
            'bootstrap position class' => ['<p>SECRET</p><div class="position-absolute bg-white">Cover</div>'],
            'painted child of a moved element' => [
                '<p>SECRET</p><div style="position:absolute;top:0"><span style="background-color:#fff">Cover</span></div>',
            ],
        ];
    }

    /**
     * A text in which a moved element could cover the rest is withheld as a whole.
     *
     * @dataProvider overlay_provider
     * @param string $html Rich text.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('overlay_provider')]
    public function test_overlay_withholds_the_text(string $html): void {
        $this->assertSame(text_sanitizer::OVERLAY_NOTE, text_sanitizer::to_plain($html), $html);
        $this->assertSame('', text_sanitizer::strip_hidden($html), $html);
        // The mark does not leak into the next text.
        $this->assertSame('Plain', text_sanitizer::to_plain('<p>Plain</p>'));
    }

    /**
     * A shadow root declared at the top of the text would take over the element of the page that holds it.
     */
    public function test_top_level_shadow_root(): void {
        $this->assertSame('', text_sanitizer::to_plain('<template shadowrootmode="open">x</template>SECRET'));
    }
}
