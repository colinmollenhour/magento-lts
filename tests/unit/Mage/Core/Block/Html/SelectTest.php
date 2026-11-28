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
use Mage_Core_Block_Html_Select as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Core\Block\Html\SelectTrait;
use ReflectionMethod;

/**
 * The select's attributes can come from stored data such as attribute codes and labels: they must be escaped
 */
final class SelectTest extends OpenMageTest
{
    use SelectTrait;

    private const ATTRIBUTE_BREAKOUT = 'code" onfocus="alert(1)" autofocus="';

    private const TAG_BREAKOUT = 'code"><script>alert(1)</script>';

    /**
     * @dataProvider provideAttribute
     * @group Block
     */
    public function testToHtmlEscapesAttributes(string $key): void
    {
        $subject = $this->getSubject();
        $subject->setData($key, self::ATTRIBUTE_BREAKOUT);

        $html = $this->render($subject);

        self::assertStringNotContainsString(self::ATTRIBUTE_BREAKOUT, $html);
        self::assertStringContainsString('code&quot; onfocus=&quot;alert(1)&quot; autofocus=&quot;', $html);

        $subject->setData($key, self::TAG_BREAKOUT);
        $html = $this->render($subject);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('code&quot;&gt;&lt;script&gt;', $html);
    }

    public static function provideAttribute(): Iterator
    {
        yield 'name' => ['name'];
        yield 'id' => ['id'];
        yield 'class' => ['class'];
        yield 'title' => ['title'];
    }

    /**
     * @group Block
     */
    public function testToHtmlEscapesOptgroupLabel(): void
    {
        $subject = $this->getSubject();
        $subject->setOptions([['label' => self::ATTRIBUTE_BREAKOUT, 'value' => [['value' => '1', 'label' => 'One']]]]);

        $html = $this->render($subject);

        self::assertStringNotContainsString(self::ATTRIBUTE_BREAKOUT, $html);
        self::assertStringContainsString('<optgroup label="code&quot; onfocus=&quot;alert(1)&quot; autofocus=&quot;">', $html);
    }

    /**
     * Plain values render as before, and a value a caller already escaped is not escaped twice
     *
     * @group Block
     */
    public function testToHtmlKeepsPlainAndPreEscapedValues(): void
    {
        $subject = $this->getSubject();
        $subject->setName('billing[region_id]')
            ->setId('billing:region_id')
            ->setClass('required-entry validate-state')
            ->setTitle('Tom &amp; Jerry');

        self::assertStringStartsWith(
            '<select name="billing[region_id]" id="billing:region_id" class="required-entry validate-state" title="Tom &amp; Jerry" >',
            $this->render($subject),
        );
    }

    private function getSubject(): Subject
    {
        $subject = new Subject();
        $subject->setName('name')->setId('id')->setClass('class')->setTitle('title')
            ->setOptions([['value' => '1', 'label' => 'One']]);

        return $subject;
    }

    private function render(Subject $subject): string
    {
        return (new ReflectionMethod($subject, '_toHtml'))->invoke($subject);
    }
}
