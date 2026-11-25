<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Adminhtml
 */

/**
 * Adminhtml sales order view gift messages controller
 *
 * @package    Mage_Adminhtml
 */
class Mage_Adminhtml_Sales_Order_View_GiftmessageController extends Mage_Adminhtml_Controller_Action
{
    /**
     * ACL resource
     * @see Mage_Adminhtml_Controller_Action::_isAllowed()
     */
    public const ADMIN_RESOURCE = 'sales/order/actions/view';

    /**
     * Gift message fields the order view posts
     *
     * Any other field, such as gift_message_id, would change which message is saved.
     */
    protected const GIFTMESSAGE_FIELDS = ['type', 'sender', 'recipient', 'message'];

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->setUsedModuleName('Mage_Sales');
    }

    /**
     * @return void
     */
    public function saveAction()
    {
        $error = null;
        try {
            $giftmessages = $this->getRequest()->getParam('giftmessage');
            if (is_array($giftmessages) && $giftmessages !== []) {
                $giftmessages = $this->_filterGiftmessages($giftmessages, $this->_getOrderEntityIds());
                if ($giftmessages === null) {
                    Mage::throwException(Mage::helper('giftmessage')->__('An error occurred while saving the gift message.'));
                }
            }

            $this->_getGiftmessageSaveModel()
                ->setGiftmessages($giftmessages)
                ->saveAllInOrder();
        } catch (Mage_Core_Exception $mageCoreException) {
            $error = $mageCoreException->getMessage();
            $this->_getSession()->addError($error);
        } catch (Exception) {
            $error = Mage::helper('giftmessage')->__('An error occurred while saving the gift message.');
            $this->_getSession()->addError($error);
        }

        if ($this->getRequest()->getParam('type') == 'order_item') {
            $this->getResponse()->setBody(
                $error === null && $this->_getGiftmessageSaveModel()->getSaved() ? 'YES' : 'NO',
            );
        } else {
            $this->getResponse()->setBody(
                $error ?? Mage::helper('giftmessage')->__('The gift message has been saved.'),
            );
        }
    }

    /**
     * Keep the posted gift messages only if each belongs to the order or to one of its items
     *
     * The order view posts the messages of one order, keyed by the order id (type "order")
     * or by an order item id (type "order_item").
     *
     * @param  array<mixed>                                   $giftmessages posted messages keyed by entity id
     * @param  null|array{order_id: int, item_ids: list<int>} $order        the order the request names
     * @return null|array<int|string, array<string, mixed>>   null when a message belongs elsewhere
     */
    protected function _filterGiftmessages(array $giftmessages, ?array $order): ?array
    {
        if ($order === null) {
            return null;
        }

        $result = [];
        foreach ($giftmessages as $entityId => $giftmessage) {
            if (!is_array($giftmessage) || (string) (int) $entityId !== (string) $entityId) {
                return null;
            }

            $belongsToOrder = match ($giftmessage['type'] ?? null) {
                'order' => (int) $entityId === $order['order_id'],
                'order_item' => in_array((int) $entityId, $order['item_ids'], true),
                default => false,
            };
            if (!$belongsToOrder) {
                return null;
            }

            $result[$entityId] = array_intersect_key($giftmessage, array_flip(static::GIFTMESSAGE_FIELDS));
        }

        return $result;
    }

    /**
     * Id and item ids of the order the request names
     *
     * The save URL names the order (type "order") or one of its items (type "order_item") in "entity".
     *
     * @return null|array{order_id: int, item_ids: list<int>}
     */
    protected function _getOrderEntityIds(): ?array
    {
        $request = $this->getRequest();
        $entityId = (int) $request->getParam('entity');
        $orderId = match ($request->getParam('type')) {
            'order' => $entityId,
            'order_item' => (int) Mage::getModel('sales/order_item')->load($entityId)->getOrderId(),
            default => 0,
        };

        if ($orderId === 0) {
            return null;
        }

        $order = Mage::getModel('sales/order')->load($orderId);
        if ((int) $order->getId() === 0) {
            return null;
        }

        $itemIds = [];
        foreach ($order->getItemsCollection() as $item) {
            $itemIds[] = (int) $item->getId();
        }

        return ['order_id' => (int) $order->getId(), 'item_ids' => $itemIds];
    }

    /**
     * Retrieve gift message save model
     *
     * @return Mage_Adminhtml_Model_Giftmessage_Save
     */
    protected function _getGiftmessageSaveModel()
    {
        return Mage::getSingleton('adminhtml/giftmessage_save');
    }
}
