<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Widget
 */

/**
 * Widget Instance Resource Model
 *
 * @package    Mage_Widget
 */
class Mage_Widget_Model_Resource_Widget_Instance extends Mage_Core_Model_Resource_Db_Abstract
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('widget/widget_instance', 'instance_id');
    }

    /**
     * Perform actions after object load
     *
     * @inheritDoc
     */
    #[Override]
    protected function _afterLoad(Mage_Core_Model_Abstract $object)
    {
        $adapter = $this->_getReadAdapter();
        $select = $adapter->select()
            ->from($this->getTable('widget/widget_instance_page'))
            ->where('instance_id = ?', (int) $object->getId());
        $result = $adapter->fetchAll($select);
        $object->setData('page_groups', $result);
        return parent::_afterLoad($object);
    }

    /**
     * Perform actions after object save
     *
     * @inheritDoc
     * @param  Mage_Widget_Model_Widget_Instance $object
     * @throws Zend_Db_Adapter_Exception
     */
    #[Override]
    protected function _afterSave(Mage_Core_Model_Abstract $object)
    {
        $pageTable         = $this->getTable('widget/widget_instance_page');
        $pageLayoutTable   = $this->getTable('widget/widget_instance_page_layout');
        $readAdapter       = $this->_getReadAdapter();
        $writeAdapter      = $this->_getWriteAdapter();

        $select = $readAdapter->select()
            ->from($pageTable, 'page_id')
            ->where('instance_id = ?', (int) $object->getId());
        $pageIds = $readAdapter->fetchCol($select);

        $removePageIds = array_diff($pageIds, $object->getDataByKey('page_group_ids'));

        if (is_array($pageIds) && $pageIds !== []) {
            $inCond = $readAdapter->prepareSqlCondition('page_id', ['in' => $pageIds]);

            $select = $readAdapter->select()
                ->from($pageLayoutTable, 'layout_update_id')
                ->where($inCond);
            $removeLayoutUpdateIds = $readAdapter->fetchCol($select);

            $writeAdapter->delete($pageLayoutTable, $inCond);
            $this->_deleteLayoutUpdates($removeLayoutUpdateIds);
        }

        $this->_deleteWidgetInstancePages($removePageIds);

        foreach ($object->getDataByKey('page_groups') as $pageGroup) {
            $pageLayoutUpdateIds = $this->_saveLayoutUpdates($object, $pageGroup);
            $data = [
                'page_group'      => $pageGroup['group'],
                'layout_handle'   => $pageGroup['layout_handle'],
                'block_reference' => $pageGroup['block_reference'],
                'page_for'        => $pageGroup['for'],
                'entities'        => $pageGroup['entities'],
                'page_template'   => $pageGroup['template'],
            ];
            $pageId = $pageGroup['page_id'];
            if (in_array($pageGroup['page_id'], $pageIds)) {
                $writeAdapter->update($pageTable, $data, ['page_id = ?' => (int) $pageId]);
            } else {
                $writeAdapter->insert(
                    $pageTable,
                    array_merge(
                        ['instance_id' => $object->getId()],
                        $data,
                    ),
                );
                $pageId = $writeAdapter->lastInsertId($pageTable);
            }

            foreach ($pageLayoutUpdateIds as $layoutUpdateId) {
                $writeAdapter->insert($pageLayoutTable, [
                    'page_id' => $pageId,
                    'layout_update_id' => $layoutUpdateId,
                ]);
            }
        }

        return parent::_afterSave($object);
    }

    /**
     * Prepare and save layout updates data
     *
     * @param  Mage_Widget_Model_Widget_Instance $widgetInstance
     * @param  array                             $pageGroupData
     * @return array                             of inserted layout updates ids
     */
    protected function _saveLayoutUpdates($widgetInstance, $pageGroupData)
    {
        $writeAdapter          = $this->_getWriteAdapter();
        $pageLayoutUpdateIds   = [];
        $storeIds              = $this->_prepareStoreIds($widgetInstance->getStoreIds());
        $layoutUpdateTable     = $this->getTable('core/layout_update');
        $layoutUpdateLinkTable = $this->getTable('core/layout_link');

        foreach ($pageGroupData['layout_handle_updates'] as $handle) {
            $xml = $widgetInstance->generateLayoutUpdateXml(
                $pageGroupData['block_reference'],
                $pageGroupData['template'],
            );
            $insert = [
                'handle'     => $handle,
                'xml'        => $xml,
            ];
            if (strlen($widgetInstance->getSortOrder())) {
                $insert['sort_order'] = $widgetInstance->getSortOrder();
            }

            $writeAdapter->insert($layoutUpdateTable, $insert);
            $layoutUpdateId = $writeAdapter->lastInsertId($layoutUpdateTable);
            $pageLayoutUpdateIds[] = $layoutUpdateId;

            $data = [];
            foreach ($storeIds as $storeId) {
                $data[] = [
                    'store_id'         => $storeId,
                    'area'             => $widgetInstance->getArea(),
                    'package'          => $widgetInstance->getPackage(),
                    'theme'            => $widgetInstance->getTheme(),
                    'layout_update_id' => $layoutUpdateId];
            }

            $writeAdapter->insertMultiple($layoutUpdateLinkTable, $data);
        }

        return $pageLayoutUpdateIds;
    }

    /**
     * Prepare store ids.
     * If one of store id is default (0) return all store ids
     *
     * @param  array $storeIds
     * @return array
     */
    protected function _prepareStoreIds($storeIds)
    {
        if (in_array('0', $storeIds)) {
            return [0];
        }

        return $storeIds;
    }

    /**
     * Perform actions before object delete.
     * Collect page ids and layout update ids and set to object for further delete
     *
     * @return $this
     */
    #[Override]
    protected function _beforeDelete(Mage_Core_Model_Abstract $object)
    {
        $writeAdapter = $this->_getWriteAdapter();
        $select = $writeAdapter->select()
            ->from(['main_table' => $this->getTable('widget/widget_instance_page')], [])
            ->joinInner(
                ['layout_page_table' => $this->getTable('widget/widget_instance_page_layout')],
                'layout_page_table.page_id = main_table.page_id',
                ['layout_update_id'],
            )
            ->where('main_table.instance_id=?', $object->getId());
        $result = $writeAdapter->fetchCol($select);
        $object->setLayoutUpdateIdsToDelete($result);
        return $this;
    }

    /**
     * Perform actions after object delete.
     * Delete layout updates by layout update ids collected in _beforeSave
     *
     * @inheritDoc
     */
    #[Override]
    protected function _afterDelete(Mage_Core_Model_Abstract $object)
    {
        $this->_deleteLayoutUpdates($object->getLayoutUpdateIdsToDelete());
        return parent::_afterDelete($object);
    }

    /**
     * Delete widget instance pages by given ids
     *
     * @param  array $pageIds
     * @return $this
     */
    protected function _deleteWidgetInstancePages($pageIds)
    {
        $writeAdapter = $this->_getWriteAdapter();
        if ($pageIds) {
            $inCond = $writeAdapter->prepareSqlCondition('page_id', [
                'in' => $pageIds,
            ]);
            $writeAdapter->delete(
                $this->getTable('widget/widget_instance_page'),
                $inCond,
            );
        }

        return $this;
    }

    /**
     * Delete layout updates by given ids
     *
     * @param  array $layoutUpdateIds
     * @return $this
     */
    protected function _deleteLayoutUpdates($layoutUpdateIds)
    {
        $writeAdapter = $this->_getWriteAdapter();
        if ($layoutUpdateIds) {
            $inCond = $writeAdapter->prepareSqlCondition('layout_update_id', [
                'in' => $layoutUpdateIds,
            ]);
            $writeAdapter->delete(
                $this->getTable('core/layout_update'),
                $inCond,
            );
        }

        return $this;
    }

    /**
     * Delete the layout updates of widget instances that the widget generator would not produce
     *
     * A layout update is removed when its widget instance has a type that no installed module
     * declares in a widget.xml, or when its XML does not have the exact shape that
     * Mage_Widget_Model_Widget_Instance::generateLayoutUpdateXml() produces for the instance's type.
     * The widget instance and its page rows are kept: saving the instance again (once its type is
     * declared) regenerates its layout updates. Running this again removes nothing more.
     *
     * @param  int                                                                                       $batchSize number of widget instances read per query
     * @return list<array{instance_id: int, type: string, reason: string, layout_update_ids: list<int>}> what was removed, per widget instance
     */
    public function deleteUnexpectedLayoutUpdates(int $batchSize = 200): array
    {
        $adapter   = $this->_getWriteAdapter();
        $batchSize = max(1, $batchSize);
        $removed   = [];
        $lastId    = 0;

        /** @var Mage_Widget_Model_Widget_Instance $checker */
        $checker = Mage::getModel('widget/widget_instance');

        do {
            $instances = $adapter->fetchPairs(
                $adapter->select()
                    ->from($this->getMainTable(), ['instance_id', 'instance_type'])
                    ->where('instance_id > ?', $lastId)
                    ->order('instance_id ' . Varien_Db_Select::SQL_ASC)
                    ->limit($batchSize),
            );
            if ($instances === []) {
                break;
            }

            $lastId = (int) max(array_keys($instances));

            $layoutRows = $adapter->fetchAll(
                $adapter->select()
                    ->from(['page' => $this->getTable('widget/widget_instance_page')], ['instance_id'])
                    ->join(
                        ['page_layout' => $this->getTable('widget/widget_instance_page_layout')],
                        'page_layout.page_id = page.page_id',
                        [],
                    )
                    ->join(
                        ['layout_update' => $this->getTable('core/layout_update')],
                        'layout_update.layout_update_id = page_layout.layout_update_id',
                        ['layout_update_id', 'xml'],
                    )
                    ->where('page.instance_id IN (?)', array_map(intval(...), array_keys($instances))),
            );

            $toDelete = [];
            foreach ($layoutRows ?? [] as $row) {
                $instanceId = (int) $row['instance_id'];
                $type = (string) $instances[$instanceId];
                $checker->setType($type);
                if ($checker->isGeneratedLayoutUpdateXml((string) $row['xml'])) {
                    continue;
                }

                $removed[$instanceId] ??= [
                    'instance_id'       => $instanceId,
                    'type'              => $type,
                    'reason'            => $checker->isTypeDeclared() ? 'unexpected layout update XML' : 'undeclared widget type',
                    'layout_update_ids' => [],
                ];
                $removed[$instanceId]['layout_update_ids'][] = (int) $row['layout_update_id'];
                $toDelete[] = (int) $row['layout_update_id'];
            }

            // core_layout_link and widget_instance_page_layout rows go with them (ON DELETE CASCADE)
            $this->_deleteLayoutUpdates($toDelete);
        } while (count($instances) === $batchSize);

        return array_values($removed);
    }

    /**
     * Get store ids to which specified item is assigned
     *
     * @param  int   $id
     * @return array
     */
    public function lookupStoreIds($id)
    {
        $adapter = $this->_getReadAdapter();
        $select = $adapter->select()
            ->from($this->getMainTable(), 'store_ids')
            ->where("{$this->getIdFieldName()} = ?", (int) $id);
        $storeIds = $adapter->fetchOne($select);
        return $storeIds ? explode(',', $storeIds) : [];
    }
}
