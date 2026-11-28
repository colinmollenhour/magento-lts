<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Core\Helper;

use Generator;
use Mage;
use Mage_Core_Block_Template;
use Mage_Core_Helper_Data as Subject;
use OpenMage\Tests\Unit\OpenMageTest;

final class JsQuoteEscapeTest extends OpenMageTest
{
    private const FUZZ_SEED = 20261125;

    private const FUZZ_CASES = 4000;

    /**
     * Characters that matter to a JavaScript string literal or to the script element around it, plus filler
     * and broken UTF-8 bytes. U+2028 and U+2029 are single entries.
     */
    private const FUZZ_ALPHABET = [
        '\\', "'", '"', 'n', 'r', 't', 'x', 'u', '3', 'c', '0', '<', '/', '!', '-', ' ', 'a',
        "\n", "\r", "\u{2028}", "\u{2029}", "\xE2", "\x80",
    ];

    private static Subject $subject;

    protected function setUp(): void
    {
        self::$subject = Mage::helper('core');
    }

    /**
     * @dataProvider provideJsQuoteEscapeData
     * @group Helper
     */
    public function testJsQuoteEscape(string $expected, string $data, string $quote): void
    {
        self::assertSame($expected, self::$subject->jsQuoteEscape($data, $quote));
    }

    public static function provideJsQuoteEscapeData(): Generator
    {
        yield 'plain text' => ['Hello world', 'Hello world', "'"];
        yield 'single quote' => ["it\\'s", "it's", "'"];
        yield 'double quote kept in single-quoted string' => ['say "hi"', 'say "hi"', "'"];
        yield 'double quote' => ['say \\"hi\\"', 'say "hi"', '"'];
        yield 'single quote kept in double-quoted string' => ["it's", "it's", '"'];
        yield 'backslash' => ['C:\\\\Temp', 'C:\\Temp', "'"];
        yield 'trailing backslash' => ['abc\\\\', 'abc\\', "'"];
        yield 'escaped quote breakout' => ["\\\\\\'-alert(1)//", "\\'-alert(1)//", "'"];
        yield 'escaped double quote breakout' => ['\\\\\\"-alert(1)//', '\\"-alert(1)//', '"'];
        yield 'hex escapes for angle brackets' => [
            '\\\\x3cimg src=x onerror=alert(1)\\\\x3e',
            '\\x3cimg src=x onerror=alert(1)\\x3e',
            "'",
        ];
        yield 'unicode escape' => ['\\\\u003c', '\\u003c', "'"];
        yield 'double backslash' => ['\\\\\\\\', '\\\\', "'"];
        yield 'translation line break sequences' => [
            'Line 1\\r\\nLine 2\\nLine 3\\tend',
            'Line 1\\r\\nLine 2\\nLine 3\\tend',
            "'",
        ];
        yield 'raw line terminators' => ['a\\nb\\rc\\u2028d\\u2029e', "a\nb\rc\u{2028}d\u{2029}e", "'"];
        yield 'backslash before raw line feed' => ['a\\\\\\nb', "a\\\nb", "'"];
        yield 'closing script tag' => ['<\\/script><script>alert(1)<\\/script>', '</script><script>alert(1)</script>', "'"];
        yield 'html comment opener' => ['\\u003C!--<script>', '<!--<script>', "'"];
        yield 'markup without closing tag kept' => ['<b>bold', '<b>bold', "'"];
        yield 'invalid utf-8 kept' => ["\xE2\x80", "\xE2\x80", "'"];
    }

    /**
     * @group Helper
     */
    public function testJsQuoteEscapeArray(): void
    {
        self::assertSame(
            ["it\\'s", 'C:\\\\Temp', '<\\/b>'],
            self::$subject->jsQuoteEscape(['first' => "it's", 'second' => 'C:\\Temp', 'third' => '</b>']),
        );
        self::assertSame(['say \\"hi\\"'], self::$subject->jsQuoteEscape(['say "hi"'], '"'));
    }

    /**
     * @group Block
     * @group Helper
     */
    public function testBlockJsQuoteEscapeUsesHelper(): void
    {
        $block = new Mage_Core_Block_Template();
        self::assertSame("\\\\\\'-alert(1)//", $block->jsQuoteEscape("\\'-alert(1)//"));
        self::assertSame(['a\\\\'], $block->jsQuoteEscape(['a\\']));
    }

    /**
     * Property check: for every input, the output placed between two quotes is exactly one JavaScript string
     * literal, its value is the input with only the \n, \r and \t sequences decoded, and it cannot end the
     * surrounding script element.
     *
     * Covers every string of up to three alphabet entries, then FUZZ_CASES seeded random strings per quote.
     *
     * @group Helper
     */
    public function testJsQuoteEscapeProducesOneStringLiteral(): void
    {
        $inputs = [''];
        $previous = [''];
        for ($length = 1; $length <= 3; $length++) {
            $next = [];
            foreach ($previous as $prefix) {
                foreach (self::FUZZ_ALPHABET as $char) {
                    $next[] = $prefix . $char;
                }
            }

            array_push($inputs, ...$next);
            $previous = $next;
        }

        mt_srand(self::FUZZ_SEED);
        $alphabetMax = count(self::FUZZ_ALPHABET) - 1;
        for ($i = 0; $i < self::FUZZ_CASES; $i++) {
            $input = '';
            for ($length = mt_rand(4, 24); $length > 0; $length--) {
                $input .= self::FUZZ_ALPHABET[mt_rand(0, $alphabetMax)];
            }

            $inputs[] = $input;
        }

        mt_srand();

        $failures = [];
        foreach (['"', "'"] as $quote) {
            foreach ($inputs as $input) {
                $output = self::$subject->jsQuoteEscape($input, $quote);
                $error = $this->checkEscaped($input, $output, $quote);
                if ($error !== null && count($failures) < 10) {
                    $failures[] = sprintf('"%s" with %s: %s (output "%s")', $this->describe($input), $quote, $error, $this->describe($output));
                }
            }
        }

        self::assertSame([], $failures);
    }

    private function checkEscaped(string $input, string $output, string $quote): ?string
    {
        $value = $this->parseJsStringLiteral($quote . $output . $quote, $quote);
        if ($value === null) {
            return 'not exactly one string literal';
        }

        $expected = strtr($input, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t"]);
        if ($value !== $expected) {
            return 'value differs: "' . $this->describe($value) . '"';
        }

        if (str_contains($output, '</') || str_contains($output, '<!--')) {
            return 'contains </ or <!--';
        }

        if (substr_count($output, '<') > substr_count($input, '<')) {
            return 'adds a <';
        }

        return null;
    }

    /**
     * Parse $source as a JavaScript string literal the way a browser does outside strict mode
     *
     * Returns the literal's value, or null when $source is not exactly one complete string literal, contains
     * a raw line terminator (U+2028 and U+2029 are refused too, as engines before ES2019 do) or a malformed
     * \x or \u escape.
     */
    private function parseJsStringLiteral(string $source, string $quote): ?string
    {
        $end = strlen($source) - 1;
        if ($end < 1 || $source[0] !== $quote) {
            return null;
        }

        $value = '';
        $i = 1;
        while ($i <= $end) {
            $char = $source[$i];
            if ($char === $quote) {
                return $i === $end ? $value : null;
            }

            if ($char === "\n" || $char === "\r" || $this->isUnicodeLineTerminatorAt($source, $i)) {
                return null;
            }

            if ($char !== '\\') {
                $value .= $char;
                $i++;
                continue;
            }

            if ($i === $end) {
                return null;
            }

            $char = $source[++$i];
            $i++;
            $simple = ['b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v"];
            if (isset($simple[$char])) {
                $value .= $simple[$char];
            } elseif ($char === "\r") {
                // line continuation, CRLF counts as one
                if (($source[$i] ?? '') === "\n") {
                    $i++;
                }
            } elseif ($char === "\n") {
                // line continuation
            } elseif ($this->isUnicodeLineTerminatorAt($source, $i - 1)) {
                $i += 2;
            } elseif ($char === 'x') {
                if (preg_match('/\G[0-9a-fA-F]{2}/', $source, $match, 0, $i) !== 1) {
                    return null;
                }

                $value .= mb_chr((int) hexdec($match[0]), 'UTF-8');
                $i += 2;
            } elseif ($char === 'u') {
                if (preg_match('/\G(?:[0-9a-fA-F]{4}|\{([0-9a-fA-F]{1,6})\})/', $source, $match, 0, $i) !== 1) {
                    return null;
                }

                $codePoint = (int) hexdec($match[1] ?? $match[0]);
                if ($codePoint > 0x10FFFF) {
                    return null;
                }

                $value .= mb_chr($codePoint, 'UTF-8');
                $i += strlen($match[0]);
            } elseif ($char >= '0' && $char <= '7') {
                // legacy octal escape, \0 included
                $maxDigits = $char <= '3' ? 3 : 2;
                $digits = preg_match('/\G[0-7]{1,' . ($maxDigits - 1) . '}/', $source, $match, 0, $i) === 1 ? $match[0] : '';
                $value .= mb_chr((int) octdec($char . $digits), 'UTF-8');
                $i += strlen($digits);
            } else {
                // NonEscapeCharacter, including ' " \ / 8 9; further bytes of a UTF-8 sequence follow as is
                $value .= $char;
            }
        }

        return null;
    }

    private function describe(string $value): string
    {
        return addcslashes($value, "\0..\37\\\"\177..\377");
    }

    private function isUnicodeLineTerminatorAt(string $source, int $offset): bool
    {
        $bytes = substr($source, $offset, 3);

        return $bytes === "\u{2028}" || $bytes === "\u{2029}";
    }
}
