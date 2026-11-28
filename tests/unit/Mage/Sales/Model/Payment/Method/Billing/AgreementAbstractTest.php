<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Sales\Model\Payment\Method\Billing;

use Iterator;
use Mage;
use Mage_Core_Exception;
use Mage_Customer_Model_Customer;
use Mage_Sales_Model_Billing_Agreement;
use Mage_Sales_Model_Payment_Method_Billing_AgreementAbstract as Subject;
use Mage_Sales_Model_Quote;
use Mage_Sales_Model_Quote_Payment;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Sales\Model\Payment\Method\Billing\AgreementAbstractTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Varien_Object;

/**
 * assignData() sets the billing agreement of the payment only from the transport field, and only when the agreement
 * belongs to the quote customer and is active. Billing agreement values that arrive any other way are cleared.
 */
final class AgreementAbstractTest extends OpenMageTest
{
    use AgreementAbstractTrait;

    private const CUSTOMER_ID = 5;

    private const OWN_ID = 7;

    private const OWN_CANCELED_ID = 8;

    private const FOREIGN_ID = 9;

    public function testSubmittedAdditionalInformationCannotSetTheAgreement(): void
    {
        $payment = $this->createPayment();

        $this->createSubject($payment)->assignData(new Varien_Object([
            'method' => 'paypal_billing_agreement',
            'additional_information' => [
                Subject::TRANSPORT_BILLING_AGREEMENT_ID => self::FOREIGN_ID,
                Subject::PAYMENT_INFO_REFERENCE_ID => 'B-FOREIGN',
            ],
        ]));

        self::assertNull($payment->getAdditionalInformation(Subject::TRANSPORT_BILLING_AGREEMENT_ID));
        self::assertNull($payment->getAdditionalInformation(Subject::PAYMENT_INFO_REFERENCE_ID));
    }

    public function testStoredAgreementIsClearedWhenNoneIsSubmitted(): void
    {
        $payment = $this->createPayment();
        $payment->setAdditionalInformation(Subject::TRANSPORT_BILLING_AGREEMENT_ID, self::FOREIGN_ID)
            ->setAdditionalInformation(Subject::PAYMENT_INFO_REFERENCE_ID, 'B-FOREIGN')
            ->setAdditionalInformation('unrelated', 'kept');

        $this->createSubject($payment)->assignData(['method' => 'paypal_billing_agreement']);

        self::assertSame(['unrelated' => 'kept'], $payment->getAdditionalInformation());
    }

    /**
     * @param array<string, int|string> $additionalInformation
     */
    #[DataProvider('provideRefusedAgreements')]
    public function testRefusesAgreementThatTheCustomerMayNotUse(int $agreementId, array $additionalInformation): void
    {
        $payment = $this->createPayment();

        try {
            $this->createSubject($payment)->assignData(new Varien_Object([
                Subject::TRANSPORT_BILLING_AGREEMENT_ID => $agreementId,
                'additional_information' => $additionalInformation,
            ]));
            self::fail('A billing agreement the customer may not use was accepted.');
        } catch (Mage_Core_Exception $mageCoreException) {
            self::assertSame('Wrong billing agreement ID specified.', $mageCoreException->getMessage());
        }

        self::assertNull($payment->getAdditionalInformation(Subject::TRANSPORT_BILLING_AGREEMENT_ID));
        self::assertNull($payment->getAdditionalInformation(Subject::PAYMENT_INFO_REFERENCE_ID));
    }

    public static function provideRefusedAgreements(): Iterator
    {
        $injected = [
            Subject::TRANSPORT_BILLING_AGREEMENT_ID => self::FOREIGN_ID,
            Subject::PAYMENT_INFO_REFERENCE_ID => 'B-FOREIGN',
        ];
        yield "another customer's agreement" => [self::FOREIGN_ID, []];
        yield 'own canceled agreement' => [self::OWN_CANCELED_ID, []];
        yield 'agreement that does not exist' => [404, []];
        yield "another customer's agreement, also in additional information" => [self::FOREIGN_ID, $injected];
    }

    public function testAcceptsOwnActiveAgreement(): void
    {
        $payment = $this->createPayment();

        $this->createSubject($payment)->assignData([
            Subject::TRANSPORT_BILLING_AGREEMENT_ID => (string) self::OWN_ID,
            'additional_information' => [Subject::PAYMENT_INFO_REFERENCE_ID => 'B-FOREIGN'],
        ]);

        self::assertSame(self::OWN_ID, $payment->getAdditionalInformation(Subject::TRANSPORT_BILLING_AGREEMENT_ID));
        self::assertSame('B-OWN', $payment->getAdditionalInformation(Subject::PAYMENT_INFO_REFERENCE_ID));
    }

    public function testSubmittedNonArrayAdditionalInformationDoesNotBreakAssignment(): void
    {
        $payment = $this->createPayment();

        $this->createSubject($payment)->assignData([
            Subject::TRANSPORT_BILLING_AGREEMENT_ID => self::OWN_ID,
            'additional_information' => 'not an array',
        ]);

        self::assertSame(self::OWN_ID, $payment->getAdditionalInformation(Subject::TRANSPORT_BILLING_AGREEMENT_ID));
        self::assertSame('B-OWN', $payment->getAdditionalInformation(Subject::PAYMENT_INFO_REFERENCE_ID));
    }

    #[DataProvider('provideIdsThatAreNotLoaded')]
    public function testLoadBillingAgreementIgnoresEmptyAndNonScalarIds(mixed $agreementId): void
    {
        $subject = new class extends Subject {
            protected function _isAvailable($quote): bool
            {
                return true;
            }
        };

        self::assertNull((new ReflectionMethod($subject, '_loadBillingAgreement'))->invoke($subject, $agreementId));
    }

    public static function provideIdsThatAreNotLoaded(): Iterator
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'zero' => ['0'];
        yield 'array' => [[self::FOREIGN_ID]];
    }

    private function createPayment(): Mage_Sales_Model_Quote_Payment
    {
        $customer = $this->createConfiguredMock(Mage_Customer_Model_Customer::class, ['getId' => self::CUSTOMER_ID]);
        $quote = $this->getMockBuilder(Mage_Sales_Model_Quote::class)->onlyMethods(['getCustomer'])->getMock();
        $quote->method('getCustomer')->willReturn($customer);

        /** @var Mage_Sales_Model_Quote_Payment $payment */
        $payment = Mage::getModel('sales/quote_payment');
        return $payment->setQuote($quote);
    }

    private function createSubject(Mage_Sales_Model_Quote_Payment $payment): Subject
    {
        $agreements = [
            self::OWN_ID => [self::CUSTOMER_ID, Mage_Sales_Model_Billing_Agreement::STATUS_ACTIVE, 'B-OWN'],
            self::OWN_CANCELED_ID => [self::CUSTOMER_ID, Mage_Sales_Model_Billing_Agreement::STATUS_CANCELED, 'B-OWN-OLD'],
            self::FOREIGN_ID => [self::CUSTOMER_ID + 1, Mage_Sales_Model_Billing_Agreement::STATUS_ACTIVE, 'B-FOREIGN'],
        ];

        $subject = new class ($agreements) extends Subject {
            /**
             * @param array<int, array{int, string, string}> $agreements
             */
            public function __construct(private readonly array $agreements)
            {
                parent::__construct();
            }

            protected function _isAvailable($quote): bool
            {
                return true;
            }

            protected function _loadBillingAgreement($agreementId)
            {
                if (!isset($this->agreements[(int) $agreementId])) {
                    return null;
                }

                [$customerId, $status, $referenceId] = $this->agreements[(int) $agreementId];
                /** @var Mage_Sales_Model_Billing_Agreement $agreement */
                $agreement = Mage::getModel('sales/billing_agreement');
                return $agreement->setData([
                    'agreement_id' => (int) $agreementId,
                    'customer_id' => $customerId,
                    'status' => $status,
                    'reference_id' => $referenceId,
                ]);
            }
        };

        return $subject->setInfoInstance($payment);
    }
}
