<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Widget
 */

/**
 * Remove storefront layout updates of widget instances that the widget generator would not produce
 * (GHSA-v7cc-v57g-wx93): instances whose type no installed module declares in a widget.xml, and
 * layout updates whose XML is not the exact shape generated for the instance's declared type.
 * Widget instances themselves are kept; saving one again regenerates its layout updates.
 */

use Monolog\Level;

/** @var Mage_Core_Model_Resource_Setup $this */
$installer = $this;
$installer->startSetup();

// Read the widget types of the modules enabled now, not a list cached before this upgrade
Mage::app()->removeCache('widget_config');

/** @var Mage_Widget_Model_Resource_Widget_Instance $resource */
$resource = Mage::getResourceSingleton('widget/widget_instance');
$removed = $resource->deleteUnexpectedLayoutUpdates();

foreach ($removed as $instance) {
    Mage::log(
        sprintf(
            'Removed layout updates %s of widget instance #%d (type %s): %s. Delete the widget instance, or save it again once its widget type is declared.',
            implode(', ', $instance['layout_update_ids']),
            $instance['instance_id'],
            // Encoded: the stored type may contain quotes, markup or line breaks
            json_encode($instance['type'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            $instance['reason'],
        ),
        Level::Warning,
        'widget_instance_cleanup.log',
        true,
    );
}

// Clean (not just invalidate) the caches that hold widget layout, so removed updates stop rendering now
$config = Mage::getConfig();
$cacheTypes = $removed !== [] && $config instanceof Mage_Core_Model_Config
    ? $config->getNode(Mage_Widget_Model_Widget_Instance::XML_NODE_RELATED_CACHE)
    : false;
if ($cacheTypes instanceof Mage_Core_Model_Config_Element) {
    $cacheTypes = $cacheTypes->asArray();
    foreach (is_array($cacheTypes) ? array_keys($cacheTypes) : [] as $cacheType) {
        Mage::app()->getCacheInstance()->cleanType($cacheType);
    }
}

$installer->endSetup();
