<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Widget\Controllers\Adminhtml\Widget;

use Mage;
use Mage_Adminhtml_Model_Session;
use Mage_Core_Controller_Request_Http;
use Mage_Widget_Adminhtml_Widget_InstanceController as Subject;
use Mage_Widget_Model_Widget_Instance;
use OpenMage\Tests\Unit\OpenMageTest;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;
use ReflectionProperty;

final class InstanceControllerTest extends OpenMageTest
{
    private const REGISTRY_KEY = 'current_widget_instance';

    protected function tearDown(): void
    {
        Mage::unregister(self::REGISTRY_KEY);
        parent::tearDown();
    }

    /**
     * A new instance may only be started with a widget type declared in a widget.xml.
     *
     * @group Controller
     */
    public function testInitWidgetInstanceRejectsUndeclaredType(): void
    {
        $session = $this->createPartialMock(Mage_Adminhtml_Model_Session::class, ['addError']);
        $session->expects(self::once())->method('addError')->with('Wrong widget type specified.');

        $subject = $this->createSubject(['type' => 'core-text_list', 'package' => 'base', 'theme' => 'default'], $session);

        self::assertFalse($this->initWidgetInstance($subject));
        self::assertNull(Mage::registry(self::REGISTRY_KEY));
    }

    /**
     * @group Controller
     */
    public function testInitWidgetInstanceAcceptsDeclaredType(): void
    {
        $session = $this->createPartialMock(Mage_Adminhtml_Model_Session::class, ['addError']);
        $session->expects(self::never())->method('addError');

        $subject = $this->createSubject(['type' => 'cms-widget_page_link', 'package' => 'base', 'theme' => 'default'], $session);

        $instance = $this->initWidgetInstance($subject);
        self::assertInstanceOf(Mage_Widget_Model_Widget_Instance::class, $instance);
        self::assertSame('cms/widget_page_link', $instance->getType());
    }

    /**
     * Without a type the Settings tab is shown so one can be picked.
     *
     * @group Controller
     */
    public function testInitWidgetInstanceAllowsMissingType(): void
    {
        $session = $this->createPartialMock(Mage_Adminhtml_Model_Session::class, ['addError']);
        $session->expects(self::never())->method('addError');

        $subject = $this->createSubject([], $session);

        self::assertInstanceOf(Mage_Widget_Model_Widget_Instance::class, $this->initWidgetInstance($subject));
    }

    /**
     * @param  array<string, string> $params
     * @return MockObject&Subject
     */
    private function createSubject(array $params, Mage_Adminhtml_Model_Session $session): MockObject
    {
        $subject = $this->createPartialMock(Subject::class, ['_getSession', '_title']);
        $subject->method('_getSession')->willReturn($session);
        $subject->method('_title')->willReturnSelf();

        $request = new Mage_Core_Controller_Request_Http();
        $request->setParams($params);
        (new ReflectionProperty($subject, '_request'))->setValue($subject, $request);

        return $subject;
    }

    private function initWidgetInstance(Subject $subject): false|Mage_Widget_Model_Widget_Instance
    {
        return (new ReflectionMethod($subject, '_initWidgetInstance'))->invoke($subject);
    }
}
