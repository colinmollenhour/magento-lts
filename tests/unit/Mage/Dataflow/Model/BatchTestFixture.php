<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Dataflow\Model;

/**
 * A class that is not a Dataflow adapter but has the methods an import calls on one
 *
 * Records whether a profile managed to construct it or run its finish() method.
 */
final class BatchTestFixture
{
    public static bool $constructed = false;

    public static bool $finished = false;

    public function __construct()
    {
        self::$constructed = true;
    }

    public static function reset(): void
    {
        self::$constructed = false;
        self::$finished = false;
    }

    public function parse(): bool
    {
        return true;
    }

    public function saveRow(): bool
    {
        return true;
    }

    public function finish(): void
    {
        self::$finished = true;
    }
}
