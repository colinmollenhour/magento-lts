<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Traits\DataProvider\Mage\Widget\Model\Widget;

use Generator;

trait InstanceTrait
{
    /**
     * @return Generator<string, array{string}>
     */
    public static function provideUndeclaredTypeData(): Generator
    {
        yield 'block that is not a widget' => ['core/text_list'];
        yield 'admin block' => ['adminhtml/template'];
        yield 'declared type followed by an attribute break-out' => ['cms/widget_page_link" x="y'];
        yield 'declared type followed by markup' => ['cms/widget_page_link"><block type="core/text'];
    }

    /**
     * @return Generator<string, array{string, mixed, string}>
     */
    public static function provideInvalidPageGroupData(): Generator
    {
        $valid = ['page_id' => 0, 'for' => 'all', 'layout_handle' => 'default', 'block' => 'content', 'template' => ''];
        $displayError = 'Wrong widget display option specified.';
        $charsError = 'Templatepath or block reference contain special characters.';

        yield 'unknown page group' => ['unknown_group', $valid, $displayError];
        yield 'page group data is not an array' => ['all_pages', 'content', $displayError];
        yield 'unknown display-for value' => ['all_pages', ['for' => 'everything'] + $valid, $displayError];
        yield 'specific entities on a page group without entities' => ['all_pages', ['for' => 'specific'] + $valid, $displayError];
        yield 'quote in block reference' => ['all_pages', ['block' => 'content"'] + $valid, $charsError];
        yield 'markup in block reference' => ['all_pages', ['block' => 'content><x'] + $valid, $charsError];
        yield 'block reference is an array' => ['all_pages', ['block' => ['content']] + $valid, $charsError];
        yield 'quote in template' => ['all_pages', ['template' => 'cms/widget/link/link_block.phtml"'] + $valid, $charsError];
    }
}
