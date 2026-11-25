<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Controllers\Sales\Order\View;

use Generator;
use Mage_Adminhtml_Model_Giftmessage_Save;
use Mage_Adminhtml_Model_Session;
use Mage_Adminhtml_Sales_Order_View_GiftmessageController as Subject;
use Mage_Core_Controller_Request_Http;
use Mage_Core_Controller_Response_Http;
use OpenMage\Tests\Unit\OpenMageTest;
use ReflectionProperty;

final class GiftmessageControllerTest extends OpenMageTest
{
    private const ORDER = ['order_id' => 7, 'item_ids' => [70, 71]];

    /**
     * The order view saves only the messages of the order it names, and only their message fields.
     *
     * @dataProvider provideSaveData
     * @param array<mixed>                                   $posted
     * @param null|array{order_id: int, item_ids: list<int>} $order
     * @param null|array<int|string, array<string, mixed>>   $expectedSaved null when the request is refused
     * @group Controller
     */
    public function testSave(string $type, array $posted, ?array $order, ?array $expectedSaved): void
    {
        $saveModel = $this->createPartialMock(Mage_Adminhtml_Model_Giftmessage_Save::class, ['saveAllInOrder']);
        $saveModel->expects($expectedSaved === null ? self::never() : self::once())->method('saveAllInOrder')->willReturnSelf();

        $session = $this->createPartialMock(Mage_Adminhtml_Model_Session::class, ['addError']);
        $session->expects($expectedSaved === null ? self::once() : self::never())->method('addError');

        $request = new Mage_Core_Controller_Request_Http();
        $request->setActionName('save')->setParams(['entity' => '7', 'type' => $type, 'giftmessage' => $posted]);

        $subject = $this->createPartialMock(Subject::class, ['_getOrderEntityIds', '_getGiftmessageSaveModel', '_getSession']);
        $subject->method('_getOrderEntityIds')->willReturn($order);
        $subject->method('_getGiftmessageSaveModel')->willReturn($saveModel);
        $subject->method('_getSession')->willReturn($session);
        (new ReflectionProperty($subject, '_request'))->setValue($subject, $request);
        (new ReflectionProperty($subject, '_response'))->setValue($subject, new Mage_Core_Controller_Response_Http());

        $subject->saveAction();

        if ($expectedSaved !== null) {
            self::assertSame($expectedSaved, $saveModel->getGiftmessages());
        }

        self::assertSame(
            $expectedSaved === null ? 'An error occurred while saving the gift message.' : 'The gift message has been saved.',
            $subject->getResponse()->getBody(),
        );
    }

    /**
     * @return Generator<string, array<mixed>>
     */
    public static function provideSaveData(): Generator
    {
        $message = ['sender' => 'Ann', 'recipient' => 'Bob', 'message' => 'Happy birthday'];

        yield 'order message saved' => ['order', [7 => ['type' => 'order', ...$message]], self::ORDER, [7 => ['type' => 'order', ...$message]]];
        yield 'item message saved' => ['order', [70 => ['type' => 'order_item', ...$message]], self::ORDER, [70 => ['type' => 'order_item', ...$message]]];
        yield 'posted message id dropped' => [
            'order',
            [7 => ['type' => 'order', 'gift_message_id' => '99', 'customer_id' => '4', ...$message]],
            self::ORDER,
            [7 => ['type' => 'order', ...$message]],
        ];
        yield 'message of another order refused' => ['order', [8 => ['type' => 'order', ...$message]], self::ORDER, null];
        yield 'item of another order refused' => ['order', [72 => ['type' => 'order_item', ...$message]], self::ORDER, null];
        yield 'order id posted as an item refused' => ['order', [7 => ['type' => 'order_item', ...$message]], self::ORDER, null];
        yield 'quote message refused' => ['order', [7 => ['type' => 'main', ...$message]], self::ORDER, null];
        yield 'message without a type refused' => ['order', [7 => $message], self::ORDER, null];
        yield 'one foreign message refuses the request' => [
            'order',
            [7 => ['type' => 'order', ...$message], 8 => ['type' => 'order', ...$message]],
            self::ORDER,
            null,
        ];
        yield 'non-numeric entity id refused' => ['order', ['7abc' => ['type' => 'order', ...$message]], self::ORDER, null];
        yield 'unknown order refused' => ['order', [7 => ['type' => 'order', ...$message]], null, null];
    }
}
