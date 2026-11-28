<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Catalog\Model\Product\Attribute;

use Mage_Catalog_Model_Resource_Eav_Attribute;
use Override;

/**
 * Product attribute that records the data it would save instead of writing to the database
 */
final class ApiTestFixture extends Mage_Catalog_Model_Resource_Eav_Attribute
{
    public const NEW_ATTRIBUTE_ID = 987;

    /**
     * @var null|array<string, mixed>
     */
    public static ?array $saved = null;

    #[Override]
    public function save()
    {
        if ($this->getId() === null) {
            $this->setId(self::NEW_ATTRIBUTE_ID);
        }

        self::$saved = $this->getData();
        return $this;
    }
}
