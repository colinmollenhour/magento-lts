<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Payment\Model\Method;

use Generator;
use Mage;
use Mage_Payment_Model_Method_Abstract as Subject;
use Mage_Sales_Model_Quote_Payment;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Payment\Model\Method\AbstractTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Varien_Object;

/**
 * The base assignData() copies the submitted payment fields, but not the fields that hold internal payment state.
 */
final class AbstractTest extends OpenMageTest
{
    use AbstractTrait;

    #[DataProvider('provideInternalKeys')]
    public function testAssignDataDoesNotCopyInternalState(string $key, bool $asObject): void
    {
        $payment = $this->createPayment();
        $payment->setData($key, 'stored');

        $submitted = ['method' => 'checkmo', $key => ['submitted']];
        $this->createSubject($payment)->assignData($asObject ? new Varien_Object($submitted) : $submitted);

        self::assertSame('stored', $payment->getData($key));
        self::assertSame('checkmo', $payment->getData('method'));
    }

    public static function provideInternalKeys(): Generator
    {
        $keys = [
            'additional_information', 'additional_data', 'method_instance',
            'payment_id', 'entity_id', 'quote_id', 'parent_id', 'quote_payment_id',
            'amount_ordered', 'base_amount_paid', 'amount_refunded', 'base_shipping_captured',
            'last_trans_id', 'cc_trans_id', 'transaction_id', 'cc_number_enc', 'cc_cid_enc',
            'cc_approval', 'cc_avs_status', 'paypal_payer_id', 'paypal_correlation_id',
        ];
        foreach ($keys as $key) {
            yield $key . ' (array)' => [$key, false];
            yield $key . ' (Varien_Object)' => [$key, true];
        }
    }

    public function testSubmittedAdditionalInformationDoesNotReplaceStoredValues(): void
    {
        $payment = $this->createPayment();
        // as loaded from the database: the additional information has not been read yet
        $payment->setData('additional_information', ['token' => 'stored-token']);

        $this->createSubject($payment)->assignData(new Varien_Object([
            'method' => 'paypal_express',
            'additional_information' => ['token' => 'submitted-token', 'ba_agreement_id' => 9],
        ]));

        self::assertSame(['token' => 'stored-token'], $payment->getAdditionalInformation());
    }

    #[DataProvider('provideCustomerKeys')]
    public function testAssignDataCopiesCustomerInput(string $key): void
    {
        $payment = $this->createPayment();

        $this->createSubject($payment)->assignData(['method' => 'checkmo', $key => 'submitted']);

        self::assertSame('submitted', $payment->getData($key));
    }

    public static function provideCustomerKeys(): Generator
    {
        $keys = ['method', 'po_number', 'cc_type', 'cc_owner', 'cc_exp_month', 'cc_last4', 'echeck_routing_number', 'gateway_token'];
        foreach ($keys as $key) {
            yield $key => [$key];
        }
    }

    private function createPayment(): Mage_Sales_Model_Quote_Payment
    {
        /** @var Mage_Sales_Model_Quote_Payment $payment */
        $payment = Mage::getModel('sales/quote_payment');
        return $payment;
    }

    private function createSubject(Mage_Sales_Model_Quote_Payment $payment): Subject
    {
        $subject = new class extends Subject {};
        return $subject->setInfoInstance($payment);
    }
}
