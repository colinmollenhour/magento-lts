<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Block\Customer\Edit\Tab\View;

use Mage;
use Mage_Admin_Model_Session;
use Mage_Adminhtml_Block_Customer_Edit_Tab_View_Accordion as Subject;
use Mage_Customer_Model_Customer;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\Customer\Edit\Tab\View\AccordionTrait;
use ReflectionMethod;

final class AccordionTest extends OpenMageTest
{
    use AccordionTrait;

    private const SESSION_KEY = '_singleton/admin/session';

    private mixed $previousSession = null;

    private mixed $previousCustomer = null;

    private mixed $previousWishlistActive = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousSession = Mage::registry(self::SESSION_KEY);
        $this->previousCustomer = Mage::registry('current_customer');
        $this->previousWishlistActive = Mage::getStoreConfig('wishlist/general/active');
    }

    protected function tearDown(): void
    {
        foreach ([self::SESSION_KEY => $this->previousSession, 'current_customer' => $this->previousCustomer] as $key => $value) {
            Mage::unregister($key);
            if ($value !== null) {
                Mage::register($key, $value);
            }
        }

        Mage::app()->getStore()->setConfig('wishlist/general/active', $this->previousWishlistActive);
        parent::tearDown();
    }

    /**
     * Recent Orders loads the lastOrders action, which needs the order View permission.
     *
     * @group Block
     */
    public function testRecentOrdersNeedOrderView(): void
    {
        // No cart or wishlist sections, which would need the database
        $customer = $this->createPartialMock(Mage_Customer_Model_Customer::class, ['getSharedWebsiteIds']);
        $customer->method('getSharedWebsiteIds')->willReturn([]);
        Mage::unregister('current_customer');
        Mage::register('current_customer', $customer);
        Mage::app()->getStore()->setConfig('wishlist/general/active', '0');

        foreach ([false, true] as $canViewOrders) {
            $session = $this->createPartialMock(Mage_Admin_Model_Session::class, ['isAllowed']);
            $session->method('isAllowed')->willReturnCallback(
                static fn(string $resource): bool => $canViewOrders && $resource === 'sales/order/actions/view',
            );
            Mage::unregister(self::SESSION_KEY);
            Mage::register(self::SESSION_KEY, $session);

            $subject = $this->createPartialMock(Subject::class, ['getUrl']);
            $subject->method('getUrl')->willReturn('http://example.com/');
            $subject->setLayout(Mage::app()->getLayout());
            (new ReflectionMethod($subject, '_prepareLayout'))->invoke($subject);

            self::assertSame($canViewOrders ? ['lastOrders'] : [], array_keys($subject->getItems()));
        }
    }
}
