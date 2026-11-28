<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Block\Sales\Order;

use Generator;
use Mage;
use Mage_Admin_Model_Session;
use Mage_Adminhtml_Block_Sales_Order_Grid as Subject;
use Mage_Adminhtml_Block_Widget_Grid_Massaction_Abstract as MassAction;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\Sales\Order\GridTrait;
use ReflectionMethod;

final class GridTest extends OpenMageTest
{
    use GridTrait;

    private const SESSION_KEY = '_singleton/admin/session';

    private mixed $previousSession = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousSession = Mage::registry(self::SESSION_KEY);
    }

    protected function tearDown(): void
    {
        Mage::unregister(self::SESSION_KEY);
        if ($this->previousSession !== null) {
            Mage::register(self::SESSION_KEY, $this->previousSession);
        }

        parent::tearDown();
    }

    /**
     * The grid offers each mass action only to the permission its controller action checks.
     *
     * @dataProvider provideMassactionData
     * @param string[] $allowed  resources the admin is allowed
     * @param string[] $expected mass action ids
     * @group Block
     */
    public function testPrepareMassaction(array $allowed, array $expected): void
    {
        $session = $this->createPartialMock(Mage_Admin_Model_Session::class, ['isAllowed']);
        $session->method('isAllowed')->willReturnCallback(static fn(string $resource): bool => in_array($resource, $allowed, true));
        Mage::unregister(self::SESSION_KEY);
        Mage::register(self::SESSION_KEY, $session);

        $layout = Mage::app()->getLayout();
        $subject = $this->createPartialMock(Subject::class, ['getUrl']);
        $subject->method('getUrl')->willReturn('http://example.com/');
        $subject->setLayout($layout);
        $subject->setChild('massaction', $layout->createBlock('adminhtml/widget_grid_massaction'));

        (new ReflectionMethod($subject, '_prepareMassaction'))->invoke($subject);

        self::assertSame($expected, array_keys($subject->getMassactionBlock()->getItems()));
    }

    /**
     * @return Generator<string, array<mixed>>
     */
    public static function provideMassactionData(): Generator
    {
        $prints = [MassAction::PDF_INVOICE_ORDER, MassAction::PDF_SHIPMENTS_ORDER];

        yield 'hold only' => [['sales/order', 'sales/order/actions/hold'], [MassAction::HOLD_ORDER]];
        yield 'view' => [['sales/order', 'sales/order/actions/view'], [...$prints, MassAction::PDF_DOCS_ORDER]];
        yield 'view and credit memos' => [
            ['sales/order', 'sales/order/actions/view', 'sales/creditmemo'],
            [...$prints, MassAction::PDF_CREDITMEMOS_ORDER, MassAction::PDF_DOCS_ORDER],
        ];
        yield 'view and order credit memos' => [
            ['sales/order', 'sales/order/actions/view', 'sales/order/actions/creditmemo'],
            [...$prints, MassAction::PDF_CREDITMEMOS_ORDER, MassAction::PDF_DOCS_ORDER],
        ];
        yield 'credit memos without view' => [['sales/order', 'sales/creditmemo'], []];
        yield 'shipments' => [['sales/order', 'sales/shipment'], [MassAction::PRINT_SHIPMENT_LABEL]];
    }
}
