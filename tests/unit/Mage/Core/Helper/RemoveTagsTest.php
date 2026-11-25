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
use Mage_Core_Helper_Data as Subject;
use OpenMage\Tests\Unit\OpenMageTest;

final class RemoveTagsTest extends OpenMageTest
{
    private static Subject $subject;

    protected function setUp(): void
    {
        self::$subject = Mage::helper('core');
    }

    /**
     * @dataProvider provideRemoveTagsData
     * @group Helper
     */
    public function testRemoveTags(string $expected, string $data): void
    {
        self::assertSame($expected, self::$subject->removeTags($data));
    }

    public static function provideRemoveTagsData(): Generator
    {
        yield 'plain text' => ['Main Website', 'Main Website'];
        yield 'tag' => ['K3 ', 'K3 <img src=x onerror="alert(1)">'];
        yield 'entity-encoded tag' => ['K3 ', 'K3 &lt;img src=x onerror="alert(1)"&gt;'];
        yield 'double entity-encoded tag' => ['K3 ', 'K3 &amp;lt;img src=x onerror=alert(1)&amp;gt;'];
        yield 'entity-encoded closing tag' => ['a  b', 'a &lt;/script&gt; b'];
        yield 'less than and greater than signs' => ['Kids < 5 & > 2', 'Kids < 5 & > 2'];
        yield 'encoded ampersand' => ['Shoes & Bags', 'Shoes &amp; Bags'];
        yield 'encoded less than sign' => ['a < b', 'a &lt; b'];
        yield 'quotes' => ['"Big" \'Shop\'', '&quot;Big&quot; &#039;Shop&#039;'];
    }

    /**
     * @group Helper
     */
    public function testRemoveTagsIsIdempotent(): void
    {
        foreach (['K3 &lt;img src=x&gt;', 'Kids < 5 & > 2', 'a &amp;amp;lt;b&amp;amp;gt; c', '&lt;&lt;b&gt;i&gt;'] as $data) {
            $once = self::$subject->removeTags($data);
            self::assertSame($once, self::$subject->removeTags($once), $data);
            self::assertDoesNotMatchRegularExpression('/<(?:b|img)/', $once, $data);
        }
    }

    /**
     * @group Helper
     */
    public function testHasTagsFlagsEntityEncodedTags(): void
    {
        self::assertTrue(self::$subject->hasTags('&lt;img src=x&gt;'));
        self::assertTrue(self::$subject->hasTags('<img src=x>'));
        self::assertFalse(self::$subject->hasTags('Kids < 5 & > 2'));
    }
}
