<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Core\Block\Html;

use Iterator;
use Mage_Core_Block_Html_Date as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Core\Block\Html\DateTrait;
use ReflectionMethod;

/**
 * The date input's attributes and the ids in its calendar script can come from stored data such as attribute
 * codes: they must be escaped for HTML and for the JavaScript string they are put in
 */
final class DateTest extends OpenMageTest
{
    use DateTrait;

    /**
     * @dataProvider provideAttribute
     * @group Block
     */
    public function testToHtmlEscapesAttributes(string $key): void
    {
        $payload = 'code" onfocus="alert(1)" autofocus="';
        $subject = $this->getSubject();
        $subject->setData($key, $payload);

        $html = $this->render($subject);

        self::assertStringNotContainsString($payload, $html);
        self::assertStringContainsString('code&quot; onfocus=&quot;alert(1)&quot; autofocus=&quot;', $html);
    }

    public static function provideAttribute(): Iterator
    {
        yield 'name' => ['name'];
        yield 'id' => ['id'];
        yield 'class' => ['class'];
    }

    /**
     * @dataProvider provideScriptId
     * @group Block
     */
    public function testToHtmlQuotesIdInScript(string $id, string $inputField, string $button): void
    {
        $subject = $this->getSubject();
        $subject->setId($id);

        $html = $this->render($subject);

        self::assertSame(1, substr_count($html, '<script'));
        self::assertSame(1, substr_count($html, '</script>'));
        self::assertStringContainsString('inputField  : ' . $inputField . ',', $html);
        self::assertStringContainsString('button      : ' . $button . ',', $html);
    }

    public static function provideScriptId(): Iterator
    {
        yield 'plain id' => ['options_1_date', '"options_1_date"', '"options_1_date_trig"'];
        yield 'quote' => ['x",a:alert(1),b:"', '"x\u0022,a:alert(1),b:\u0022"', '"x\u0022,a:alert(1),b:\u0022_trig"'];
        yield 'script end tag' => [
            'x</script><script>alert(1)</script>',
            '"x\u003C\/script\u003E\u003Cscript\u003Ealert(1)\u003C\/script\u003E"',
            '"x\u003C\/script\u003E\u003Cscript\u003Ealert(1)\u003C\/script\u003E_trig"',
        ];
        yield 'cdata end' => ['x]]>', '"x]]\u003E"', '"x]]\u003E_trig"'];
    }

    private function getSubject(): Subject
    {
        $subject = new Subject();
        $subject->setName('name')->setId('id')->setClass('input-text')
            ->setFormat('M/d/yy')->setImage('calendar.gif');

        return $subject;
    }

    private function render(Subject $subject): string
    {
        return (new ReflectionMethod($subject, '_toHtml'))->invoke($subject);
    }
}
