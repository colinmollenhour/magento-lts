<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Varien\Io;

use Exception;
use Iterator;
use OpenMage\Tests\Unit\OpenMageTest;
use Override;
use Varien_Io_File as Subject;

final class FileTest extends OpenMageTest
{
    public const MALICIOUS_PATH_MESSAGE = 'Detected malicious path or filename input.';

    /**
     * Sandbox the adapter is opened on
     */
    private static string $workingDirectory;

    /**
     * Sibling directory sharing a prefix with the working directory
     */
    private static string $siblingDirectory;

    #[Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openmage-varien-io-file-' . uniqid();

        self::$workingDirectory = $base . DIRECTORY_SEPARATOR . 'root';
        self::$siblingDirectory = $base . DIRECTORY_SEPARATOR . 'root-evil';

        mkdir(self::$workingDirectory . DIRECTORY_SEPARATOR . 'sub', 0777, true);
        mkdir(self::$siblingDirectory, 0777, true);
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        Subject::rmdirRecursive(dirname(self::$workingDirectory));

        parent::tearDownAfterClass();
    }

    /**
     * Adapter opened on the sandbox, as every core caller does before writing
     */
    private function getSubject(): Subject
    {
        $subject = new Subject();
        $subject->open(['path' => self::$workingDirectory]);

        return $subject;
    }

    /**
     * @group Varien_Io
     */
    public function testWriteRelativeFileName(): void
    {
        $subject = $this->getSubject();

        self::assertNotFalse($subject->write('legit.txt', 'content'));
        self::assertSame('content', file_get_contents(self::$workingDirectory . DIRECTORY_SEPARATOR . 'legit.txt'));
    }

    /**
     * @group Varien_Io
     */
    public function testWriteRelativeFileNameInSubFolder(): void
    {
        $subject = $this->getSubject();

        self::assertNotFalse($subject->write('sub/legit.txt', 'content'));
        self::assertSame('content', file_get_contents(self::$workingDirectory . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'legit.txt'));
    }

    /**
     * Varien_Convert_Adapter_Io::save() passes pwd() . '/' . $filename
     *
     * @group Varien_Io
     */
    public function testWriteAbsoluteFileNameInsideWorkingDirectory(): void
    {
        $subject = $this->getSubject();

        $fileName = $subject->pwd() . '/absolute.txt';

        self::assertNotFalse($subject->write($fileName, 'content'));
        self::assertSame('content', file_get_contents($fileName));
    }

    /**
     * @group Varien_Io
     */
    public function testWriteFromSourceFileInsideWorkingDirectory(): void
    {
        $subject = $this->getSubject();

        $source = self::$siblingDirectory . DIRECTORY_SEPARATOR . 'source.txt';
        file_put_contents($source, 'content');

        self::assertNotFalse($subject->write('copied.txt', $source));
        self::assertSame('content', file_get_contents(self::$workingDirectory . DIRECTORY_SEPARATOR . 'copied.txt'));
    }

    /**
     * @group Varien_Io
     */
    public function testWriteRejectsParentTraversal(): void
    {
        $subject = $this->getSubject();

        self::expectException(Exception::class);
        self::expectExceptionMessage(self::MALICIOUS_PATH_MESSAGE);

        $subject->write('../escaped.txt', 'content');
    }

    /**
     * @group Varien_Io
     */
    public function testWriteRejectsNullByte(): void
    {
        $subject = $this->getSubject();

        self::expectException(Exception::class);
        self::expectExceptionMessage(self::MALICIOUS_PATH_MESSAGE);

        $subject->write("legit.txt\0.php", 'content');
    }

    /**
     * The sibling directory shares a prefix with the working directory
     *
     * @group Varien_Io
     */
    public function testWriteRejectsAbsolutePathInPrefixedSiblingDirectory(): void
    {
        $subject = $this->getSubject();

        self::expectException(Exception::class);
        self::expectExceptionMessage(self::MALICIOUS_PATH_MESSAGE);

        $subject->write(self::$siblingDirectory . DIRECTORY_SEPARATOR . 'escaped.txt', 'content');
    }

    /**
     * GHSA-57qq-p497-7grq: an absolute path planted a PHP payload below the application root
     *
     * @group Varien_Io
     */
    public function testWriteRejectsAbsolutePathOutsideWorkingDirectory(): void
    {
        $subject = $this->getSubject();

        self::expectException(Exception::class);
        self::expectExceptionMessage(self::MALICIOUS_PATH_MESSAGE);

        $subject->write(dirname(self::$workingDirectory, 2) . DIRECTORY_SEPARATOR . 'shell.jpg', 'content');
    }

    /**
     * @group Varien_Io
     */
    public function testWriteRejectsAbsolutePathBelowApplicationRoot(): void
    {
        $subject = $this->getSubject();

        self::expectException(Exception::class);
        self::expectExceptionMessage(self::MALICIOUS_PATH_MESSAGE);

        $subject->write(BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'shell.jpg', 'content');
    }

    /**
     * Callers that never open() a working directory asked for no boundary, so write()
     * keeps its previous behaviour for them.
     *
     * @group Varien_Io
     */
    public function testWriteWithoutWorkingDirectoryIsNotConfined(): void
    {
        $subject = new Subject();
        $fileName = self::$siblingDirectory . DIRECTORY_SEPARATOR . 'no-boundary.txt';

        self::assertNotFalse($subject->write($fileName, 'content'));
        self::assertSame('content', file_get_contents($fileName));
    }

    /**
     * A symlink below the working directory (e.g. media or var on shared storage) is
     * the merchant's own layout and stays writable.
     *
     * @group Varien_Io
     */
    public function testWriteThroughSymlinkBelowWorkingDirectory(): void
    {
        $link = self::$workingDirectory . DIRECTORY_SEPARATOR . 'linked';
        if (!@symlink(self::$siblingDirectory, $link)) {
            self::markTestSkipped('symlink() not available');
        }

        $subject = $this->getSubject();

        self::assertNotFalse($subject->write('linked/through-link.txt', 'content'));
        self::assertNotFalse($subject->write($link . DIRECTORY_SEPARATOR . 'absolute-through-link.txt', 'content'));
        self::assertSame('content', file_get_contents(self::$siblingDirectory . DIRECTORY_SEPARATOR . 'through-link.txt'));
    }

    /**
     * @group Varien_Io
     */
    public function testWriteAcceptsDotSegmentsInsideWorkingDirectory(): void
    {
        $subject = $this->getSubject();

        $fileName = self::$workingDirectory . DIRECTORY_SEPARATOR . '.' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'dot.txt';
        self::assertNotFalse($subject->write($fileName, 'content'));
    }

    /**
     * GHSA-gc76-8qvx-h92g: a file:// name passed the lexical check as a relative name
     *
     * @group Varien_Io
     */
    public function testWriteRejectsStreamWrapper(): void
    {
        $subject = $this->getSubject();
        $fileName = self::$siblingDirectory . DIRECTORY_SEPARATOR . 'wrapper.txt';

        try {
            $subject->write('file://' . $fileName, 'content');
            self::fail('A stream wrapper name was written');
        } catch (Exception $exception) {
            self::assertSame(self::MALICIOUS_PATH_MESSAGE, $exception->getMessage());
        }

        self::assertFileDoesNotExist($fileName);
    }

    /**
     * @group Varien_Io
     */
    public function testReadRelativeAndAbsoluteFileNameInsideWorkingDirectory(): void
    {
        file_put_contents(self::$workingDirectory . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'readable.txt', 'content');
        $subject = $this->getSubject();

        self::assertSame('content', $subject->read('sub/readable.txt'));
        self::assertSame('content', $subject->read($subject->pwd() . '/sub/readable.txt'));
    }

    /**
     * GHSA-gc76-8qvx-h92g: read() resolved absolute names, URLs and stream wrappers anywhere
     *
     * @dataProvider provideReadOutsideWorkingDirectory
     * @group Varien_Io
     */
    public function testReadRejectsFileOutsideWorkingDirectory(string $prefix, string $suffix): void
    {
        file_put_contents(self::$siblingDirectory . DIRECTORY_SEPARATOR . 'secret.txt', 'secret');
        $subject = $this->getSubject();

        self::expectException(Exception::class);
        self::expectExceptionMessage(self::MALICIOUS_PATH_MESSAGE);

        $subject->read($prefix . self::$siblingDirectory . DIRECTORY_SEPARATOR . 'secret.txt' . $suffix);
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function provideReadOutsideWorkingDirectory(): Iterator
    {
        yield 'absolute' => ['', ''];
        yield 'file wrapper' => ['file://', ''];
        yield 'php filter' => ['php://filter/resource=', ''];
    }

    /**
     * @group Varien_Io
     */
    public function testReadRejectsParentTraversal(): void
    {
        file_put_contents(self::$siblingDirectory . DIRECTORY_SEPARATOR . 'secret.txt', 'secret');
        $subject = $this->getSubject();

        self::expectException(Exception::class);
        self::expectExceptionMessage(self::MALICIOUS_PATH_MESSAGE);

        $subject->read('../' . basename(self::$siblingDirectory) . '/secret.txt');
    }

    /**
     * The destination of read() is the caller's own choice and is not confined
     *
     * @group Varien_Io
     */
    public function testReadCopiesToDestinationOutsideWorkingDirectory(): void
    {
        file_put_contents(self::$workingDirectory . DIRECTORY_SEPARATOR . 'source-for-copy.txt', 'content');
        $destination = self::$siblingDirectory . DIRECTORY_SEPARATOR . 'copy-destination.txt';
        $subject = $this->getSubject();

        self::assertTrue($subject->read('source-for-copy.txt', $destination));
        self::assertSame('content', file_get_contents($destination));
    }

    /**
     * @group Varien_Io
     */
    public function testReadWithoutWorkingDirectoryIsNotConfined(): void
    {
        $fileName = self::$siblingDirectory . DIRECTORY_SEPARATOR . 'unconfined.txt';
        file_put_contents($fileName, 'content');

        self::assertSame('content', (new Subject())->read($fileName));
    }
}
