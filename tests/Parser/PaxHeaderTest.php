<?php

namespace JakubBoucek\Tar\Tests\Parser;

use Generator;
use JakubBoucek\Tar\StreamReader;
use JakubBoucek\Tar\Tests\ResourceGenerators\MassiveFileStreamWrapper;
use JakubBoucek\Tar\Tests\Traits\GeneratesLargesFiles;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ResourceGenerators/MassiveFileStreamWrapper.php';

class PaxHeaderTest extends TestCase
{
    use GeneratesLargesFiles;

    protected function setUp(): void
    {
        if (getenv('CI') != 'true' && getenv('TEST_MASSIVE_FILE') != 'true') {
            $this->markTestSkipped('Massive file test is skipped. Set TEST_MASSIVE_FILE=true to run it.');
        }
        parent::setUp();
    }

    /**
     * @dataProvider fileCountProvider
     */
    public function testPaxHeader(int $fileCount): void
    {
        $fileGenerator = (function () use ($fileCount) {
            for ($i = 0; $i < $fileCount; $i++) {
                yield from $this->generateSingleFile();
            }
        })();
        $resource = fopen('massive-file://', 'r', context: stream_context_create([
            'massive-file' => ['generator' => $fileGenerator],
        ]));
        $this->assertIsResource($resource);

        try {
            $streamReader = new StreamReader($resource);

            $iterator = $streamReader->getIterator();
            $count = 0;
            foreach ($iterator as $file) {
                $count++;
                $fileResource = $file->getContent()->detach();
                $bytesRead = 0;
                while (!feof($fileResource)) {
                    $bytesRead += strlen(fread($fileResource, 1024 ** 2));
                }
                $this->assertSame($file->getSize(), $bytesRead);
            }
            $this->assertSame($fileCount, $count);
        } finally {
            fclose($resource);
        }
    }
}
