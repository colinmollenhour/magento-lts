<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\CatalogSearch\Model\Resource\Advanced;

use LogicException;
use Override;
use Varien_Db_Adapter_Pdo_Mysql;
use Zend_Db_Expr;
use Zend_Db_Select;

/**
 * Database adapter that builds SQL without ever connecting to a database
 */
final class CollectionTestFixture extends Varien_Db_Adapter_Pdo_Mysql
{
    public const CONFIG = ['dbname' => 'test', 'username' => 'test', 'password' => 'test'];

    #[Override]
    protected function _connect(): void
    {
        throw new LogicException('The fixture never connects to a database.');
    }

    /**
     * Quotes like the parent, without the connection the parent needs for it
     *
     * @param mixed $value
     * @param mixed $type
     */
    #[Override]
    public function quote($value, $type = null): string
    {
        if ($value instanceof Zend_Db_Select) {
            return '(' . $value->assemble() . ')';
        }

        if ($value instanceof Zend_Db_Expr) {
            return $value->__toString();
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn($item): string => $this->quote($item), $value));
        }

        return is_int($value) || is_float($value) ? (string) $value : "'" . addslashes((string) $value) . "'";
    }
}
