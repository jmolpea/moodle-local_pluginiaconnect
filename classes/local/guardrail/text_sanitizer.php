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

namespace local_pluginiaconnect\local\guardrail;

/**
 * Turns user-written rich text into plain text without hidden content.
 *
 * Hidden text is the usual carrier of instructions planted for an AI assistant,
 * so everything a person reading the page would not see is removed before the
 * text is converted: comments, scripts, styles, elements hidden by attribute,
 * by inline style or by the usual utility classes, text whose colour cannot be
 * told from its background, and invisible characters.
 *
 * The rule throughout is: when in doubt, remove. A style value this class does
 * not understand (a calc() size, an unknown colour name, a background image)
 * hides the content it applies to. Losing a decorated sentence is acceptable;
 * passing on text nobody could see is not.
 *
 * Font size and opacity are reckoned along the whole chain of ancestors, since
 * each level multiplies the one above it.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class text_sanitizer {
    /** @var int Maximum length of one text field. */
    public const MAX_FIELD_CHARS = 4000;
    /** @var string Mark appended to a cut text. */
    public const TRUNCATION_MARK = ' [truncated]';
    /** @var string Returned instead of a text that carries its own style sheet. */
    public const STYLESHEET_NOTE = '[Text not shown: it carries its own style sheet, so its hidden parts cannot be told '
        . 'apart from the visible ones. Read it on the site.]';

    /** @var string Returned instead of a text in which one part may be laid over another. */
    public const OVERLAY_NOTE = '[Text not shown: part of it is positioned over the rest, so what a reader sees cannot '
        . 'be told from what is covered. Read it on the site.]';

    /** @var float Text smaller than this many CSS pixels counts as hidden. */
    private const MIN_FONT_PX = 6.0;
    /** @var float Opacity below this counts as hidden. */
    private const MIN_OPACITY = 0.2;
    /** @var float A clipped box lower or narrower than this many CSS pixels has no room for a line of text. */
    private const MIN_CLIPPED_BOX_PX = 12.0;
    /** @var float A margin below this many CSS pixels can pull an element over the line before it. */
    private const MIN_MARGIN_PX = -16.0;
    /** @var float Letter or word spacing below this many CSS pixels piles the characters on top of each other. */
    private const MIN_SPACING_PX = -2.0;
    /** @var float Text whose contrast ratio with its background is below this counts as hidden. */
    private const MIN_CONTRAST = 1.5;
    /** @var int[] Background assumed when none is declared: a white page. */
    private const PAGE_BACKGROUND = [255, 255, 255];
    /** @var int[] Text colour assumed when none is declared: near black, as Moodle themes use. */
    private const PAGE_COLOUR = [33, 37, 41];
    /** @var float Font size assumed for the page, in CSS pixels. */
    private const PAGE_FONT_PX = 16.0;
    /** @var float Width assumed for the box a percentage margin refers to, in CSS pixels. */
    private const CONTAINER_PX = 1000.0;

    /**
     * @var array Elements and classes that make the text smaller than that of their parent: name => factor.
     *      Each level multiplies, so a pile of them can shrink the text to nothing.
     */
    private const SMALLER = ['small' => 0.8, 'sub' => 0.75, 'sup' => 0.75];

    /** @var array Absolute font-size keywords, in CSS pixels. */
    private const FONT_KEYWORDS = ['xx-small' => 9.0, 'x-small' => 10.0, 'small' => 13.0, 'medium' => 16.0,
        'large' => 18.0, 'x-large' => 24.0, 'xx-large' => 32.0, 'xxx-large' => 48.0];

    /**
     * @var array Classes that make an element, or its text, partly transparent: class => opacity. Like the
     *      opacity property, the effect of nested elements multiplies.
     */
    private const OPACITY_CLASSES = ['opacity-25' => 0.25, 'opacity-50' => 0.5, 'opacity-75' => 0.75,
        'text-opacity-25' => 0.25, 'text-opacity-50' => 0.5, 'text-opacity-75' => 0.75,
        'link-opacity-10' => 0.1, 'link-opacity-25' => 0.25, 'link-opacity-50' => 0.5, 'link-opacity-75' => 0.75];

    /** @var string[] Elements that never carry visible text. */
    private const DROPPED_TAGS = ['script', 'style', 'template', 'noscript', 'head', 'title', 'iframe', 'object', 'embed',
        'svg', 'math', 'textarea', 'select', 'option', 'datalist', 'audio', 'video', 'canvas', 'map', 'dialog', 'frame',
        'frameset', 'noframes', 'applet', 'link', 'meta', 'base', 'rp', 'noembed', 'meter', 'progress', 'marquee',
        'img', 'picture', 'area', 'xmp', 'plaintext', 'listing'];

    /** @var string[] Elements that paint over what lies under them whatever their style. */
    private const OPAQUE_TAGS = ['img', 'picture', 'video', 'canvas', 'svg', 'iframe', 'object', 'embed', 'table', 'hr',
        'input', 'button', 'textarea', 'select', 'fieldset', 'meter', 'progress', 'marquee'];

    /** @var string[] Classes that always hide content in Moodle and Bootstrap themes. */
    private const HIDDEN_CLASSES = ['hidden', 'd-none', 'sr-only', 'sr-only-focusable', 'visually-hidden',
        'visually-hidden-focusable', 'accesshide', 'invisible', 'hide', 'opacity-0', 'collapsing', 'modal', 'offcanvas',
        'text-hide', 'text-transparent', 'tooltip', 'popover', 'invalid-feedback', 'valid-feedback', 'invalid-tooltip',
        'valid-tooltip', 'hiddenifjs', 'dir-ltr-hide', 'dir-rtl-hide', 'custom-control-input', 'btn-check',
        'placeholder', 'placeholder-glow', 'placeholder-wave', 'placeholder-xs', 'placeholder-sm', 'placeholder-lg',
        'moodle-dialogue-hidden', 'yui3-overlay-hidden', 'yui3-widget-hidden', 'yui3-hidden'];

    /**
     * @var string Any other class whose name ends in "hidden" (yui3-*-hidden, *-dialogue-hidden and the
     *      like): YUI, Bootstrap and the Moodle themes use that ending for what is not shown. The overflow
     *      classes are the exception: they clip, and are treated as the overflow property.
     */
    private const HIDDEN_CLASS_PATTERN = '/(?:^|[-_])hidden$/';

    /** @var string Classes that clip the content of a box, like the overflow property. */
    private const CLIPPING_CLASS_PATTERN = '/^overflow-(?:[xy]-)?(?:hidden|auto|scroll|clip)$/';

    /** @var array Classes that hide content unless another class is present: class => class that shows it. */
    private const CONDITIONAL_CLASSES = ['collapse' => 'show', 'fade' => 'show', 'tab-pane' => 'active',
        'dropdown-menu' => 'show', 'toast' => 'show', 'carousel-item' => 'active'];

    /** @var bool Set while a fragment is walked when a moved element could cover other text. */
    private static bool $overlay = false;

    /** @var array Bootstrap text colour classes, as Moodle's Boost theme defines them. */
    private const TEXT_CLASSES = ['text-white' => [255, 255, 255], 'text-light' => [248, 249, 250],
        'text-dark' => [52, 58, 64], 'text-black' => [0, 0, 0], 'text-body' => [33, 37, 41],
        'text-muted' => [108, 117, 125], 'text-primary' => [15, 108, 191], 'text-secondary' => [108, 117, 125],
        'text-success' => [53, 122, 50], 'text-danger' => [202, 49, 32], 'text-warning' => [240, 173, 78],
        'text-info' => [0, 129, 150], 'text-white-50' => [255, 255, 255], 'text-black-50' => [128, 128, 128],
        'link-primary' => [15, 108, 191], 'link-secondary' => [206, 212, 218], 'link-success' => [53, 122, 50],
        'link-danger' => [202, 49, 32], 'link-warning' => [240, 173, 78], 'link-info' => [0, 129, 150],
        'link-light' => [248, 249, 250], 'link-dark' => [52, 58, 64], 'link-white' => [255, 255, 255],
        'link-body-emphasis' => [0, 0, 0]];

    /**
     * @var array Bootstrap classes that set a background and pick the text colour to go with it
     *      (text-bg-*, btn-*): prefix => true. The name after the prefix is one of the theme colours.
     */
    private const PAIRED_PREFIXES = ['text-bg-', 'btn-'];

    /** @var array Bootstrap background classes, as Moodle's Boost theme defines them. */
    private const BACKGROUND_CLASSES = ['bg-white' => [255, 255, 255], 'bg-light' => [248, 249, 250],
        'bg-dark' => [52, 58, 64], 'bg-black' => [0, 0, 0], 'bg-primary' => [15, 108, 191],
        'bg-secondary' => [206, 212, 218], 'bg-success' => [53, 122, 50], 'bg-danger' => [202, 49, 32],
        'bg-warning' => [240, 173, 78], 'bg-info' => [0, 129, 150], 'bg-body' => [255, 255, 255]];

    /** @var array CSS colour names understood. Any other name is treated as unknown and hides the text. */
    private const COLOUR_NAMES = [
        'black' => [0, 0, 0], 'white' => [255, 255, 255], 'red' => [255, 0, 0], 'green' => [0, 128, 0],
        'blue' => [0, 0, 255], 'yellow' => [255, 255, 0], 'orange' => [255, 165, 0], 'purple' => [128, 0, 128],
        'gray' => [128, 128, 128], 'grey' => [128, 128, 128], 'silver' => [192, 192, 192], 'maroon' => [128, 0, 0],
        'navy' => [0, 0, 128], 'teal' => [0, 128, 128], 'olive' => [128, 128, 0], 'lime' => [0, 255, 0],
        'aqua' => [0, 255, 255], 'cyan' => [0, 255, 255], 'fuchsia' => [255, 0, 255], 'magenta' => [255, 0, 255],
        'brown' => [165, 42, 42], 'pink' => [255, 192, 203], 'gold' => [255, 215, 0], 'indigo' => [75, 0, 130],
        'violet' => [238, 130, 238], 'crimson' => [220, 20, 60], 'darkred' => [139, 0, 0], 'darkgreen' => [0, 100, 0],
        'darkblue' => [0, 0, 139], 'darkgray' => [169, 169, 169], 'darkgrey' => [169, 169, 169],
        'dimgray' => [105, 105, 105], 'dimgrey' => [105, 105, 105], 'lightgray' => [211, 211, 211],
        'lightgrey' => [211, 211, 211], 'gainsboro' => [220, 220, 220], 'whitesmoke' => [245, 245, 245],
        'snow' => [255, 250, 250], 'ivory' => [255, 255, 240], 'ghostwhite' => [248, 248, 255],
        'floralwhite' => [255, 250, 240], 'aliceblue' => [240, 248, 255], 'azure' => [240, 255, 255],
        'mintcream' => [245, 255, 250], 'honeydew' => [240, 255, 240], 'seashell' => [255, 245, 238],
        'beige' => [245, 245, 220], 'linen' => [250, 240, 230], 'oldlace' => [253, 245, 230],
        'lavenderblush' => [255, 240, 245], 'lightyellow' => [255, 255, 224], 'lightcyan' => [224, 255, 255],
        'cornsilk' => [255, 248, 220], 'lemonchiffon' => [255, 250, 205], 'antiquewhite' => [250, 235, 215],
        'papayawhip' => [255, 239, 213], 'blanchedalmond' => [255, 235, 205], 'mistyrose' => [255, 228, 225],
        'lavender' => [230, 230, 250], 'lightblue' => [173, 216, 230], 'lightgreen' => [144, 238, 144],
        'lightpink' => [255, 182, 193], 'skyblue' => [135, 206, 235], 'steelblue' => [70, 130, 180],
        'royalblue' => [65, 105, 225], 'dodgerblue' => [30, 144, 255], 'tomato' => [255, 99, 71],
        'coral' => [255, 127, 80], 'salmon' => [250, 128, 114], 'firebrick' => [178, 34, 34],
        'forestgreen' => [34, 139, 34], 'seagreen' => [46, 139, 87], 'slategray' => [112, 128, 144],
        'slategrey' => [112, 128, 144], 'darkorange' => [255, 140, 0], 'darkslategray' => [47, 79, 79],
        'darkslategrey' => [47, 79, 79], 'midnightblue' => [25, 25, 112], 'chocolate' => [210, 105, 30],
        'sienna' => [160, 82, 45], 'khaki' => [240, 230, 140], 'tan' => [210, 180, 140], 'wheat' => [245, 222, 179],
        'rebeccapurple' => [102, 51, 153],
    ];

    /**
     * Plain text of a rich text value, without hidden content, cut to the field limit.
     *
     * @param string $html Rich text (HTML or plain text).
     * @param int $maxchars Maximum length.
     * @return string
     */
    public static function to_plain(string $html, int $maxchars = self::MAX_FIELD_CHARS): string {
        if (trim($html) === '') {
            return '';
        }
        $html = \core_text::substr(fix_utf8($html), 0, 200000);
        if (self::has_stylesheet($html)) {
            return self::STYLESHEET_NOTE;
        }
        $visible = self::strip_hidden($html);
        if (self::$overlay) {
            return self::OVERLAY_NOTE;
        }
        $text = html_to_text($visible, 0, false);
        $text = self::strip_invisible_characters($text);
        $text = trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n{3,}/u", "\n\n", $text)));
        return self::truncate($text, $maxchars);
    }

    /**
     * Whether a fragment carries its own style sheet (a style element or a linked one).
     *
     * A style sheet can hide any element of the fragment by class, identifier, tag, attribute or
     * position, and this class does not interpret style sheets. What is shown and what is hidden
     * can then not be told apart, so the text is not returned at all.
     *
     * @param string $html HTML fragment.
     * @return bool
     */
    public static function has_stylesheet(string $html): bool {
        return (bool) preg_match('~<(?:style|link)(?:[\s/>]|$)~i', $html);
    }

    /**
     * Cleans a value that is already plain text (names, titles).
     *
     * @param string $text Plain text.
     * @param int $maxchars Maximum length.
     * @return string
     */
    public static function clean_plain(string $text, int $maxchars = self::MAX_FIELD_CHARS): string {
        $text = self::strip_invisible_characters(fix_utf8($text));
        return self::truncate(trim($text), $maxchars);
    }

    /**
     * Cuts a text and marks the cut.
     *
     * @param string $text Text.
     * @param int $maxchars Maximum length, mark included.
     * @return string
     */
    public static function truncate(string $text, int $maxchars = self::MAX_FIELD_CHARS): string {
        if (\core_text::strlen($text) <= $maxchars) {
            return $text;
        }
        $keep = max(0, $maxchars - \core_text::strlen(self::TRUNCATION_MARK));
        return \core_text::substr($text, 0, $keep) . self::TRUNCATION_MARK;
    }

    /**
     * Removes characters a reader does not see: control characters, zero-width and filler characters,
     * bidirectional controls, line and paragraph separators, the object replacement character, the
     * blank braille pattern, musical format controls, variation selectors and Unicode tag characters.
     *
     * @param string $text Text.
     * @return string
     */
    public static function strip_invisible_characters(string $text): string {
        $pattern = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{034F}\x{061C}'
            . '\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{200B}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{206F}'
            . '\x{2800}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{FFF9}-\x{FFFC}\x{1D173}-\x{1D17A}'
            . '\x{E0000}-\x{E0FFF}]/u';
        // The narrow no-break space and the medium mathematical space fall in the ranges above; put a space back
        // for the two separators so that words do not run together.
        $text = preg_replace('/[\x{2028}\x{2029}\x{202F}\x{205F}]/u', ' ', $text) ?? '';
        $clean = preg_replace($pattern, '', $text);
        return $clean === null ? '' : $clean;
    }

    /**
     * Removes from an HTML fragment everything a reader would not see.
     *
     * @param string $html HTML fragment.
     * @return string HTML with only visible content; empty when it cannot be parsed.
     */
    public static function strip_hidden(string $html): string {
        self::$overlay = false;
        if (self::has_stylesheet($html)) {
            // Fail closed: see has_stylesheet().
            return '';
        }
        // Constructs a browser treats as comments but the parser used here may keep as text: comments
        // (also unterminated ones), CDATA sections, declarations and processing instructions.
        $html = preg_replace('/<!--.*?(?:--!?>|$)/s', '', $html) ?? '';
        $html = preg_replace('/<!\[CDATA\[.*?(?:\]\]>|$)/si', '', $html) ?? '';
        $html = preg_replace('/<[!?][^>]*(?:>|$)/s', '', $html) ?? '';
        // An end tag that does not start with a letter ("</ text >") is a comment for a browser and text for the parser.
        $html = preg_replace('~</[^A-Za-z>][^>]*(?:>|$)~s', '', $html) ?? '';
        if (trim($html) === '') {
            return '';
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="pc-root">' . $html . '</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            // Fail closed: text that cannot be parsed is not returned at all.
            return '';
        }
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//comment() | //processing-instruction()')) as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
        $roots = $xpath->query('//div[@id="pc-root"]');
        $root = $roots->length ? $roots->item(0) : null;
        if (!$root instanceof \DOMElement) {
            return '';
        }
        // A declarative shadow root replaces everything else its host shows. The host is dropped; when the
        // host would be the element of the page that holds this text, nothing of it can be trusted.
        foreach (iterator_to_array($xpath->query('//template[@shadowrootmode or @shadowroot]')) as $template) {
            $host = $template->parentNode;
            if (!$host instanceof \DOMElement || $host->isSameNode($root)) {
                return '';
            }
            if ($host->parentNode) {
                $host->parentNode->removeChild($host);
            }
        }
        self::prune($root, self::PAGE_BACKGROUND, self::PAGE_COLOUR, self::PAGE_FONT_PX, 1.0, 0);
        if (self::$overlay) {
            // Fail closed: an element moved over the rest may cover text this class would report as visible.
            return '';
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    /**
     * Walks an element: removes hidden children and text that cannot be told from its background.
     *
     * @param \DOMElement $element Element whose children are examined.
     * @param int[] $background Effective background colour inherited from the ancestors.
     * @param int[] $colour Effective text colour inherited from the ancestors.
     * @param float $fontpx Effective font size, in CSS pixels, built up along the ancestors.
     * @param float $opacity Effective opacity: the product of the opacities of the ancestors.
     * @param int $depth Nesting depth, to stop on absurdly deep markup.
     * @return void
     */
    private static function prune(
        \DOMElement $element,
        array $background,
        array $colour,
        float $fontpx,
        float $opacity,
        int $depth
    ): void {
        if ($depth > 200) {
            // Nobody nests visible text this deep: drop the rest.
            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
            return;
        }
        $invisibletext = self::contrast($colour, $background) < self::MIN_CONTRAST;
        // Text placed directly inside a closed details element, outside its summary, is not shown either.
        if (strtolower($element->tagName) === 'details' && !$element->hasAttribute('open')) {
            $invisibletext = true;
        }
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                if ($invisibletext) {
                    $element->removeChild($child);
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                $element->removeChild($child);
                continue;
            }
            $look = self::appearance($child, $background, $colour, $fontpx, $opacity);
            if ($look === null) {
                $element->removeChild($child);
                continue;
            }
            self::prune($child, $look[0], $look[1], $look[2], $look[3], $depth + 1);
        }
    }

    /**
     * How an element looks: its effective background, text colour, font size and opacity, or null when
     * it is hidden.
     *
     * Font size and opacity are carried along the ancestors, because their effect multiplies: five
     * nested elements at 40 % of the size of their parent, or four at 40 % opacity, each pass on their
     * own and together leave nothing to read.
     *
     * @param \DOMElement $element Element.
     * @param int[] $background Background inherited from the ancestors.
     * @param int[] $colour Text colour inherited from the ancestors.
     * @param float $fontpx Font size inherited from the ancestors, in CSS pixels.
     * @param float $opacity Product of the opacities of the ancestors.
     * @return array|null [background, colour, font size, opacity], or null when the element is not shown at all.
     */
    private static function appearance(
        \DOMElement $element,
        array $background,
        array $colour,
        float $fontpx,
        float $opacity
    ): ?array {
        $tag = strtolower($element->tagName);
        // Checked first, also for elements dropped below: an image moved over the text covers it all the same.
        $movedclasses = preg_split('/\s+/', strtolower($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
        $movedstyle = self::parse_style($element->getAttribute('style'));
        if (($movedstyle === null || self::is_moved($movedclasses, $movedstyle)) && self::paints_over($element)) {
            self::$overlay = true;
        }
        if (in_array($tag, self::DROPPED_TAGS, true)) {
            return null;
        }
        if ($element->hasAttribute('hidden') || strtolower(trim($element->getAttribute('aria-hidden'))) === 'true') {
            return null;
        }
        if ($tag === 'input' && strtolower(trim($element->getAttribute('type'))) === 'hidden') {
            return null;
        }
        // The content of a closed details element is hidden, except its summary.
        $parent = $element->parentNode;
        if (
            $tag !== 'summary' && $parent instanceof \DOMElement && strtolower($parent->tagName) === 'details'
            && !$parent->hasAttribute('open')
        ) {
            return null;
        }

        $classes = preg_split('/\s+/', strtolower($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
        if (array_intersect($classes, self::HIDDEN_CLASSES)) {
            return null;
        }
        $clipping = false;
        $inheritedpx = $fontpx;
        $fontpx *= self::SMALLER[$tag] ?? 1.0;
        // Classes that set a background together with its text colour come first: the utility classes
        // below are declared after them in the theme and win.
        foreach ($classes as $class) {
            foreach (self::PAIRED_PREFIXES as $prefix) {
                $name = str_starts_with($class, $prefix) ? substr($class, strlen($prefix)) : '';
                if ($name !== '' && isset(self::BACKGROUND_CLASSES['bg-' . $name])) {
                    $background = self::BACKGROUND_CLASSES['bg-' . $name];
                    $colour = self::contrast([255, 255, 255], $background) >= 4.5 ? [255, 255, 255] : self::PAGE_COLOUR;
                }
                $outline = $prefix === 'btn-' && str_starts_with($name, 'outline-') ? 'text-' . substr($name, 8) : '';
                if (isset(self::TEXT_CLASSES[$outline])) {
                    // An outline button: the theme colour as text, nothing behind it.
                    $colour = self::TEXT_CLASSES[$outline];
                }
            }
        }
        foreach ($classes as $class) {
            if (preg_match('/^d-(sm|md|lg|xl|xxl|print)-none$/', $class)) {
                return null;
            }
            if (preg_match(self::CLIPPING_CLASS_PATTERN, $class)) {
                $clipping = true;
            } else if (preg_match(self::HIDDEN_CLASS_PATTERN, $class)) {
                return null;
            }
            if (isset(self::CONDITIONAL_CLASSES[$class]) && !in_array(self::CONDITIONAL_CLASSES[$class], $classes, true)) {
                return null;
            }
            if (isset(self::TEXT_CLASSES[$class])) {
                $colour = self::TEXT_CLASSES[$class];
            }
            if (isset(self::BACKGROUND_CLASSES[$class])) {
                $background = self::BACKGROUND_CLASSES[$class];
            }
            if (isset(self::OPACITY_CLASSES[$class])) {
                $opacity *= self::OPACITY_CLASSES[$class];
            }
            if ($class === 'small') {
                $fontpx *= self::SMALLER['small'];
            }
        }
        // An image behind the text, set by the attribute of old markup: what the text is read against is unknown.
        if ($element->hasAttribute('background')) {
            return null;
        }

        // Presentational attributes of old markup.
        foreach (['color', 'text', 'bgcolor'] as $attribute) {
            if (!$element->hasAttribute($attribute) || ($attribute === 'color' && $tag !== 'font' && $tag !== 'basefont')) {
                continue;
            }
            $parsed = self::parse_colour($element->getAttribute($attribute), true);
            if ($parsed === null || $parsed === 'transparent') {
                return null;
            }
            if (is_array($parsed) && $attribute === 'bgcolor') {
                $background = $parsed;
            } else if (is_array($parsed)) {
                $colour = $parsed;
            }
        }

        $declared = self::parse_style($element->getAttribute('style'));
        if ($declared === null) {
            return null;
        }
        if ($clipping) {
            // A clipping class counts as the declaration it stands for.
            $declared['overflow'][] = 'hidden';
        }
        // A browser keeps, for each property, the last declaration it understands, and "!important" changes
        // the order. This class does not try to guess which one wins: the element is hidden when any of the
        // declarations of a property would hide it.
        $rules = array_map(static fn(array $values): string => end($values), $declared);
        if (self::rules_hide($rules)) {
            return null;
        }
        foreach ($declared as $property => $values) {
            foreach (array_slice($values, 0, -1) as $value) {
                if (self::rules_hide([$property => $value] + $rules)) {
                    return null;
                }
            }
        }
        // Size and opacity, built up along the ancestors. With several declarations of one property the
        // smallest result counts, as everywhere else in this class.
        $sizes = [];
        foreach ($declared['font-size'] ?? [] as $value) {
            $sizes[] = self::font_px($value, $inheritedpx);
        }
        foreach ($declared['font'] ?? [] as $value) {
            // In the shorthand the size is what comes before the slash; a number without a unit is a weight.
            foreach (preg_split('/\s+/', explode('/', $value, 2)[0], -1, PREG_SPLIT_NO_EMPTY) as $token) {
                if (
                    isset(self::FONT_KEYWORDS[$token]) || in_array($token, ['smaller', 'larger'], true)
                    || preg_match('/^[+-]?[0-9.]+(?:e[+-]?\d+)?(?:[a-z]+|%)$/', $token)
                ) {
                    $sizes[] = self::font_px($token, $inheritedpx);
                }
            }
        }
        if ($sizes) {
            if (in_array(null, $sizes, true)) {
                return null;
            }
            $fontpx = min($sizes);
        }
        foreach ($declared['zoom'] ?? [] as $value) {
            // Zoom scales the text of the element and of everything inside it.
            $factor = self::parse_number($value);
            if ($factor === null) {
                return null;
            }
            $fontpx *= min(1.0, max(0.0, $factor));
        }
        $factors = [];
        foreach ($declared['opacity'] ?? [] as $value) {
            $factor = self::parse_number($value);
            if ($factor === null) {
                return null;
            }
            $factors[] = min(1.0, max(0.0, $factor));
        }
        $opacity *= $factors ? min($factors) : 1.0;
        if ($fontpx < self::MIN_FONT_PX || $opacity < self::MIN_OPACITY) {
            return null;
        }
        // Any image behind the text (a gradient is one): the colour the text is read against is unknown.
        foreach ($declared['background-image'] ?? [] as $value) {
            if (!in_array($value, ['none', 'inherit', 'initial', 'unset', 'revert'], true)) {
                return null;
            }
        }
        $backgrounds = [$background];
        foreach (['background', 'background-color'] as $property) {
            foreach ($declared[$property] ?? [] as $value) {
                $parsed = self::parse_background($value);
                if ($parsed === null) {
                    return null;
                }
                if (is_array($parsed)) {
                    $background = $parsed;
                    $backgrounds[] = $parsed;
                }
            }
        }
        $colours = [$colour];
        foreach (['color', '-webkit-text-fill-color'] as $property) {
            foreach ($declared[$property] ?? [] as $value) {
                $parsed = self::parse_colour($value);
                if ($parsed === null || $parsed === 'transparent') {
                    return null;
                }
                if (is_array($parsed)) {
                    $colour = $parsed;
                    $colours[] = $parsed;
                }
            }
        }
        if (count($backgrounds) > 2 || count($colours) > 2) {
            // Several colours declared: the text must be readable with every combination of them.
            foreach ($colours as $candidate) {
                foreach ($backgrounds as $behind) {
                    if (self::contrast($candidate, $behind) < self::MIN_CONTRAST) {
                        return null;
                    }
                }
            }
        }
        return [$background, $colour, $fontpx, $opacity];
    }

    /**
     * Font size a font-size value gives to an element, in CSS pixels.
     *
     * @param string $value CSS value, lower case.
     * @param float $parentpx Font size of the parent element, which relative values refer to.
     * @return float|null Pixels; null when the value cannot be understood.
     */
    private static function font_px(string $value, float $parentpx): ?float {
        if (isset(self::FONT_KEYWORDS[$value])) {
            return self::FONT_KEYWORDS[$value];
        }
        if ($value === 'smaller') {
            return $parentpx * self::SMALLER['small'];
        }
        if ($value === 'larger') {
            return $parentpx * 1.2;
        }
        if (in_array($value, ['inherit', 'initial', 'unset', 'revert', 'revert-layer'], true)) {
            // Never above what the ancestors left: a reset cannot bring hidden text back in this reckoning.
            return $parentpx;
        }
        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)(%|em|ex|ch|lh)$/', $value, $match)) {
            $relative = ['%' => 0.01, 'em' => 1.0, 'ex' => 0.5, 'ch' => 0.5, 'lh' => 1.2];
            return (float) $match[1] * $relative[$match[2]] * $parentpx;
        }
        return self::parse_length($value);
    }

    /**
     * Whether an element is taken out of its place in the text: positioned, shifted, pulled by a
     * negative margin or transformed. Such an element can end up over other text.
     *
     * @param string[] $classes Classes of the element, lower case.
     * @param array $declared Property => values, from {@see self::parse_style()}.
     * @return bool
     */
    private static function is_moved(array $classes, array $declared): bool {
        $moving = ['position-absolute', 'position-fixed', 'position-sticky', 'fixed-top', 'fixed-bottom', 'sticky-top',
            'sticky-bottom', 'stretched-link'];
        if (array_intersect($classes, $moving)) {
            return true;
        }
        $transforms = ['transform', '-webkit-transform', '-ms-transform', 'translate', 'scale', 'rotate'];
        foreach ($declared as $property => $values) {
            foreach ($values as $value) {
                if ($property === 'position' && !in_array($value, ['static', 'relative'], true)) {
                    return true;
                }
                if (in_array($property, $transforms, true) && $value !== 'none') {
                    return true;
                }
                $offset = in_array($property, ['left', 'top', 'right', 'bottom'], true) || str_starts_with($property, 'inset');
                if (!$offset && !str_starts_with($property, 'margin')) {
                    continue;
                }
                foreach (explode(' ', $value) as $part) {
                    $length = $offset ? self::parse_length($part) : self::margin_px($part);
                    $shifted = $length === null ? $part !== 'auto' : $length != 0;
                    $pulled = $length !== null && $length < self::MIN_MARGIN_PX;
                    if ($offset ? $shifted : $pulled) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Whether an element, or anything inside it, paints something that would cover text lying under it:
     * a background, a border, a shadow, an image, a table, a form control, or any class (a class may bring
     * any of those from the theme).
     *
     * @param \DOMElement $element Element.
     * @return bool
     */
    private static function paints_over(\DOMElement $element): bool {
        $nodes = [$element];
        foreach ($element->getElementsByTagName('*') as $node) {
            $nodes[] = $node;
        }
        foreach ($nodes as $node) {
            if (
                in_array(strtolower($node->tagName), self::OPAQUE_TAGS, true) || trim($node->getAttribute('class')) !== ''
                || $node->hasAttribute('bgcolor') || $node->hasAttribute('background') || $node->hasAttribute('border')
            ) {
                return true;
            }
            $declared = self::parse_style($node->getAttribute('style'));
            if ($declared === null) {
                return true;
            }
            foreach (array_keys($declared) as $property) {
                if (preg_match('/^(background|border|box-shadow|outline|backdrop|filter|mask|column-rule)/', $property)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Parses an inline style into its declarations, after undoing CSS escapes and comments.
     *
     * @param string $style Value of a style attribute.
     * @return array|null Property => every value declared for it, in order, all lower case; null when the
     *         style cannot be trusted.
     */
    private static function parse_style(string $style): ?array {
        if (trim($style) === '') {
            return [];
        }
        if (strlen($style) > 10000) {
            return null;
        }
        $style = preg_replace('#/\*.*?\*/#s', '', $style) ?? '';
        // CSS escapes: a backslash and up to six hexadecimal digits (with an optional space), or a backslash
        // and any other character. Decoding them first defeats spellings such as "d\69splay".
        $style = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static function ($match) {
            $code = hexdec($match[1]);
            return ($code > 0 && $code < 0x110000) ? (string) \core_text::code2utf8($code) : '';
        }, $style) ?? '';
        $style = preg_replace('/\\\\(.)/su', '$1', $style) ?? '';
        if (str_contains($style, '/*') || str_contains($style, '\\')) {
            return null;
        }
        $style = strtolower(self::strip_invisible_characters($style));
        $rules = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $property = trim($parts[0]);
            $value = trim(preg_replace('/\s+/', ' ', str_replace('!important', '', $parts[1])));
            if ($property !== '' && $value !== '') {
                $rules[$property][] = $value;
            }
        }
        return $rules;
    }

    /**
     * Whether a set of declarations hides the element.
     *
     * @param array $rules Property => value, from {@see self::parse_style()}.
     * @return bool
     */
    private static function rules_hide(array $rules): bool {
        if (
            ($rules['display'] ?? '') === 'none' || str_contains($rules['display'] ?? '', 'none')
            || in_array($rules['visibility'] ?? '', ['hidden', 'collapse'], true)
            || ($rules['content-visibility'] ?? '') === 'hidden'
        ) {
            return true;
        }
        // Anything that makes the element transparent.
        foreach (['opacity', 'zoom'] as $property) {
            if (isset($rules[$property])) {
                $number = self::parse_number($rules[$property]);
                if ($number === null || $number < self::MIN_OPACITY) {
                    return true;
                }
            }
        }
        // Characters or words piled on top of each other.
        foreach (['letter-spacing', 'word-spacing'] as $property) {
            if (isset($rules[$property]) && !in_array($rules[$property], ['normal', 'inherit', 'initial', 'unset'], true)) {
                $length = self::parse_length($rules[$property]);
                if ($length === null || $length < self::MIN_SPACING_PX) {
                    return true;
                }
            }
        }
        foreach (['filter', '-webkit-filter', 'backdrop-filter'] as $property) {
            if (isset($rules[$property]) && $rules[$property] !== 'none') {
                return true;
            }
        }
        // Blending the text with what lies under it can make it vanish (black text lightened over a white
        // page is white), and a text security style replaces every character with a dot.
        $neutral = ['mix-blend-mode' => 'normal', '-webkit-text-security' => 'none', 'text-security' => 'none'];
        foreach ($neutral as $property => $off) {
            if (isset($rules[$property]) && !in_array($rules[$property], [$off, 'inherit', 'initial', 'unset'], true)) {
                return true;
            }
        }
        // Text too small to read.
        if (isset($rules['font-size']) && self::font_too_small($rules['font-size'])) {
            return true;
        }
        if (isset($rules['font'])) {
            foreach (preg_split('#[\s/]+#', $rules['font']) as $token) {
                if (preg_match('/^[+-]?[0-9.]/', $token) && self::font_too_small($token)) {
                    return true;
                }
            }
        }
        if (isset($rules['line-height'])) {
            $number = self::parse_number($rules['line-height']);
            $length = self::parse_length($rules['line-height']);
            if (($number !== null && $number <= 0.05) || ($number === null && $length !== null && $length <= 1)) {
                return true;
            }
        }
        // Boxes with no room for their content.
        $clipped = false;
        foreach (['overflow', 'overflow-x', 'overflow-y', 'overflow-block', 'overflow-inline'] as $property) {
            if (isset($rules[$property]) && preg_match('/hidden|clip|scroll|auto/', $rules[$property])) {
                $clipped = true;
            }
        }
        foreach (['width', 'height', 'max-width', 'max-height', 'inline-size', 'block-size'] as $property) {
            if (
                !isset($rules[$property]) || in_array($rules[$property], ['auto', 'none', 'fit-content', 'max-content',
                    'min-content', 'inherit', 'initial', 'unset'], true)
            ) {
                continue;
            }
            $length = self::parse_length($rules[$property]);
            if ($length === null) {
                if ($clipped) {
                    return true;
                }
                continue;
            }
            if ($length <= 0 || ($length < self::MIN_CLIPPED_BOX_PX && $clipped)) {
                return true;
            }
        }
        // Content moved out of view.
        foreach (
            ['left', 'top', 'right', 'bottom', 'inset', 'inset-inline-start', 'inset-block-start',
                'inset-inline-end', 'inset-block-end', 'text-indent'] as $property
        ) {
            if (!isset($rules[$property]) || in_array($rules[$property], ['auto', 'inherit', 'initial', 'unset'], true)) {
                continue;
            }
            foreach (explode(' ', $rules[$property]) as $part) {
                if (in_array($part, ['auto', 'each-line', 'hanging'], true)) {
                    continue;
                }
                $length = self::parse_length($part);
                if ($length === null || $length < 0 || $length >= 500) {
                    return true;
                }
            }
        }
        // Margins. A percentage refers to the width of the containing box; a value that cannot be measured
        // (calc(), var(), an unknown unit) is treated like one that moves the content out of view.
        foreach (
            ['margin', 'margin-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-inline-start',
                'margin-block-start', 'margin-inline-end', 'margin-block-end', 'margin-inline', 'margin-block'] as $property
        ) {
            if (!isset($rules[$property])) {
                continue;
            }
            foreach (explode(' ', $rules[$property]) as $part) {
                if (in_array($part, ['auto', 'inherit', 'initial', 'unset', 'revert'], true)) {
                    continue;
                }
                $length = self::margin_px($part);
                if ($length === null || $length <= -100 || $length >= 2000) {
                    return true;
                }
            }
        }
        // Any geometric trick: a legitimate course text does not need them.
        foreach (
            ['transform', '-webkit-transform', '-ms-transform', 'translate', 'scale', 'rotate', 'clip', 'clip-path',
                '-webkit-clip-path', 'mask', 'mask-image', '-webkit-mask', '-webkit-mask-image', 'perspective'] as $property
        ) {
            if (isset($rules[$property]) && !in_array($rules[$property], ['none', 'auto', 'inherit', 'initial', 'unset'], true)) {
                return true;
            }
        }
        if (isset($rules['position']) && in_array($rules['position'], ['fixed', 'sticky'], true)) {
            return true;
        }
        if (isset($rules['z-index'])) {
            $number = self::parse_number($rules['z-index']);
            if ($number !== null && $number < 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Length of one margin value in approximate CSS pixels. A percentage is resolved against the
     * assumed width of the containing box, not against the font size.
     *
     * @param string $value One value of a margin declaration, lower case.
     * @return float|null Pixels; null when the value is not a plain length.
     */
    private static function margin_px(string $value): ?float {
        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)%$/', $value, $match)) {
            return (float) $match[1] / 100 * self::CONTAINER_PX;
        }
        return self::parse_length($value);
    }

    /**
     * Whether a font-size value is below the readable minimum, or cannot be understood.
     *
     * @param string $value CSS value.
     * @return bool
     */
    private static function font_too_small(string $value): bool {
        $keywords = ['xx-small', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large', 'xxx-large', 'larger',
            'smaller', 'inherit', 'initial', 'unset', 'revert', 'normal', 'bold', 'italic'];
        if (in_array($value, $keywords, true)) {
            return false;
        }
        $length = self::parse_length($value);
        return $length === null || $length < self::MIN_FONT_PX;
    }

    /**
     * Parses a plain number or a percentage.
     *
     * @param string $value CSS value.
     * @return float|null The number (a percentage is divided by 100); null when it is neither.
     */
    private static function parse_number(string $value): ?float {
        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)(%?)$/', trim($value), $match)) {
            return (float) $match[1] / ($match[2] === '%' ? 100 : 1);
        }
        return null;
    }

    /**
     * Parses a CSS length into approximate CSS pixels.
     *
     * Relative units are resolved against a 16 pixel font and a 1000 pixel viewport; that is precise
     * enough to tell readable text from hidden text.
     *
     * @param string $value CSS value.
     * @return float|null Pixels; null when the value is not a plain length (calc(), var(), keywords).
     */
    private static function parse_length(string $value): ?float {
        if (!preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)([a-z%]*)$/', trim($value), $match)) {
            return null;
        }
        $factors = ['' => 1, 'px' => 1, 'pt' => 4 / 3, 'pc' => 16, 'in' => 96, 'cm' => 37.8, 'mm' => 3.78, 'q' => 0.945,
            'em' => 16, 'rem' => 16, 'ex' => 8, 'ch' => 8, 'lh' => 19, 'rlh' => 19, '%' => 0.16, 'vw' => 10, 'vh' => 10,
            'vmin' => 10, 'vmax' => 10, 'svw' => 10, 'svh' => 10, 'lvw' => 10, 'lvh' => 10, 'dvw' => 10, 'dvh' => 10,
            'vi' => 10, 'vb' => 10];
        if (!isset($factors[$match[2]])) {
            return null;
        }
        return (float) $match[1] * $factors[$match[2]];
    }

    /**
     * Parses a background declaration.
     *
     * @param string $value CSS value of background or background-color.
     * @return array|string|null [r, g, b] for an opaque colour; 'inherit' when it does not change the
     *         effective background; null when it cannot be understood (images, gradients).
     */
    private static function parse_background(string $value) {
        if (preg_match('/url\(|gradient\(|image\(|element\(|var\(|calc\(/', $value)) {
            return null;
        }
        if (in_array($value, ['none', 'transparent', 'inherit', 'initial', 'unset', 'revert'], true)) {
            return 'inherit';
        }
        $parsed = self::parse_colour($value);
        if ($parsed === 'transparent') {
            return 'inherit';
        }
        if ($parsed !== null) {
            return $parsed;
        }
        // A shorthand such as "#fff none repeat": take the colour among its parts.
        $found = 'inherit';
        foreach (preg_split('/\s+(?![^(]*\))/', $value) as $part) {
            if (
                in_array($part, ['none', 'repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'scroll', 'fixed', 'local', 'center',
                    'top', 'left', 'right', 'bottom', 'cover', 'contain', 'border-box', 'padding-box', 'content-box'], true)
                    || preg_match('#^[0-9.%/a-z-]*[0-9][0-9.%/a-z-]*$#', $part) && !str_starts_with($part, '#')
            ) {
                continue;
            }
            $colour = self::parse_colour($part);
            if ($colour === null) {
                return null;
            }
            if (is_array($colour)) {
                $found = $colour;
            }
        }
        return $found;
    }

    /**
     * Parses a CSS colour.
     *
     * @param string $value CSS colour value.
     * @param bool $legacy Whether the value comes from an HTML attribute (hex digits may lack the hash).
     * @return array|string|null [r, g, b] for a visible colour; 'transparent' for a fully or nearly
     *         transparent one; 'inherit' for keywords that keep the inherited colour; null when unknown.
     */
    private static function parse_colour(string $value, bool $legacy = false) {
        $value = strtolower(trim($value));
        if ($legacy && preg_match('/^[0-9a-f]{6}$/', $value)) {
            $value = '#' . $value;
        }
        if (in_array($value, ['inherit', 'initial', 'unset', 'revert', 'currentcolor'], true)) {
            return 'inherit';
        }
        if ($value === 'transparent') {
            return 'transparent';
        }
        if (isset(self::COLOUR_NAMES[$value])) {
            return self::COLOUR_NAMES[$value];
        }
        if (preg_match('/^#([0-9a-f]{3,8})$/', $value, $match)) {
            $hex = $match[1];
            if (strlen($hex) === 3 || strlen($hex) === 4) {
                $hex = implode('', array_map(static fn($digit) => $digit . $digit, str_split($hex)));
            }
            if (strlen($hex) !== 6 && strlen($hex) !== 8) {
                return null;
            }
            if (strlen($hex) === 8 && hexdec(substr($hex, 6, 2)) / 255 < self::MIN_OPACITY) {
                return 'transparent';
            }
            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        }
        if (!preg_match('/^(rgba?|hsla?)\(([^()]*)\)$/', $value, $match)) {
            return null;
        }
        $parts = preg_split('#[\s,/]+#', trim($match[2]), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 3 || count($parts) > 4) {
            return null;
        }
        if (isset($parts[3])) {
            $alpha = self::parse_number($parts[3]);
            if ($alpha === null) {
                return null;
            }
            if ($alpha < self::MIN_OPACITY) {
                return 'transparent';
            }
        }
        if (str_starts_with($match[1], 'rgb')) {
            $rgb = [];
            foreach (array_slice($parts, 0, 3) as $part) {
                if (!preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+))(%?)$/', $part, $channel)) {
                    return null;
                }
                $number = (float) $channel[1] * ($channel[2] === '%' ? 2.55 : 1);
                $rgb[] = (int) round(max(0, min(255, $number)));
            }
            return $rgb;
        }
        if (!preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+))(deg|turn|rad|grad)?$/', $parts[0], $hue)) {
            return null;
        }
        $degrees = (float) $hue[1] * (['turn' => 360, 'rad' => 180 / M_PI, 'grad' => 0.9][$hue[2] ?? ''] ?? 1);
        $saturation = self::parse_number($parts[1]);
        $lightness = self::parse_number($parts[2]);
        if ($saturation === null || $lightness === null || !str_ends_with($parts[1], '%') || !str_ends_with($parts[2], '%')) {
            return null;
        }
        return self::hsl_to_rgb(fmod(fmod($degrees, 360) + 360, 360), max(0, min(1, $saturation)), max(0, min(1, $lightness)));
    }

    /**
     * Converts a colour from hue, saturation and lightness.
     *
     * @param float $hue Hue in degrees, 0 to 360.
     * @param float $saturation Saturation, 0 to 1.
     * @param float $lightness Lightness, 0 to 1.
     * @return int[] [r, g, b]
     */
    private static function hsl_to_rgb(float $hue, float $saturation, float $lightness): array {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $sector = $hue / 60;
        $second = $chroma * (1 - abs(fmod($sector, 2) - 1));
        $base = match ((int) floor($sector) % 6) {
            0 => [$chroma, $second, 0],
            1 => [$second, $chroma, 0],
            2 => [0, $chroma, $second],
            3 => [0, $second, $chroma],
            4 => [$second, 0, $chroma],
            default => [$chroma, 0, $second],
        };
        $offset = $lightness - $chroma / 2;
        return array_map(static fn($channel) => (int) round(($channel + $offset) * 255), $base);
    }

    /**
     * WCAG contrast ratio of two colours, from 1 (identical) to 21 (black on white).
     *
     * @param int[] $first [r, g, b]
     * @param int[] $second [r, g, b]
     * @return float
     */
    private static function contrast(array $first, array $second): float {
        $luminance = static function (array $rgb): float {
            $linear = array_map(static function ($channel) {
                $value = $channel / 255;
                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, $rgb);
            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };
        $a = $luminance($first);
        $b = $luminance($second);
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
