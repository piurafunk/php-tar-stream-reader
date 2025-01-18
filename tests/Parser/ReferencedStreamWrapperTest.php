<?php

namespace JakubBoucek\Tar\Tests\Parser;

use JakubBoucek\Tar\StreamReader;
use JakubBoucek\Tar\Tests\Traits\GeneratesLargesFiles;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/ReferencedStreamWrapper.php';
require_once __DIR__ . '/../ResourceGenerators/MassiveFileStreamWrapper.php';

class ReferencedStreamWrapperTest extends TestCase
{
    use GeneratesLargesFiles;

    protected function setUp(): void
    {
        if (getenv('CI') != 'true' && getenv('TEST_MASSIVE_FILE') != 'true') {
            $this->markTestSkipped('Massive file test is skipped. Set TEST_MASSIVE_FILE=true to run it.');
        }
        parent::setUp();
    }

    public function testPaxHeader(): void
    {
        $fileGenerator = (function () {
            for ($i = 0; $i < 2; $i++) {
                yield from $this->generateSingleFile();
            }
        })();
        $resource = fopen('massive-file://', 'r', context: stream_context_create([
            'massive-file' => ['generator' => $fileGenerator],
        ]));
        $this->assertIsResource($resource);

        try {
            $streamReader = new StreamReader($resource);

            foreach ($streamReader->getIterator() as $key => $file) {
                $fileResource = $file->getContent()->asResource();
                $bytesRead = 0;
                while (!feof($fileResource)) {
                    $bytesRead += strlen(fread($fileResource, 1024 ** 2));
                }
                $this->assertSame($file->getSize(), $bytesRead);
            }
        } finally {
            fclose($resource);
        }
    }
}
