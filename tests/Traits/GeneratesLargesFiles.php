<?php

namespace JakubBoucek\Tar\Tests\Traits;

use Generator;
use JakubBoucek\Tar\Tests\ResourceGenerators\MassiveFileStreamWrapper;

trait GeneratesLargesFiles
{
    protected function generateSingleFile(?int $chunkSize = null, ?int $totalSize = null): Generator
    {
        $chunkSize ??= 1024 * 1024; // 1MB chunks
        $totalSize ??= 10 * 1024 * $chunkSize; // 10GB
        assert($totalSize > $chunkSize);
        $chunks = floor($totalSize / $chunkSize);
        $extra = $totalSize % $chunkSize;

        if ($totalSize > MassiveFileStreamWrapper::MAX_USTAR_SIZE) {
            // Generate a pax header and data record for a file larger than 8GB
            $data = implode("\n", [
                    implode(' ', array_reverse([$data = 'size=' . $totalSize, strlen($data)])),
                ]) . "\n";
            $dataLength = strlen($data);
            $data = str_pad($data, 512 * ceil($dataLength / 512), "\0");

            yield str_pad(implode('', [
                /* 0 */ 'name' => str_pad('massive_file.txt', 100, "\0"),
                /*100*/ 'mode' => str_pad('', 8, "\0"),
                /*108*/ 'uid' => str_pad('', 8, "\0"),
                /*116*/ 'gid' => str_pad('', 8, "\0"),
                /*100*/ 'size' => str_pad(decoct($dataLength), 12, "\0"),
                /*136*/ 'mtime' => str_pad('', 12, "\0"),
                /*148*/ 'chksum' => str_pad('', 8, "\0"),
                /*156*/ 'typeflag' => 'x',
                /*157*/ 'linkname' => str_pad('', 100, "\0"),
                /*257*/ 'magic' => "ustar\0",
                /*263*/ 'version' => str_pad('', 2, "\0"),
                /*265*/ 'uname' => str_pad('', 32, "\0"),
                /*297*/ 'gname' => str_pad('', 32, "\0"),
                /*329*/ 'devmajor' => str_pad('', 8, "\0"),
                /*337*/ 'devminor' => str_pad('', 8, "\0"),
                /*345*/ 'prefix' => str_pad('PaxHeaders.0', 155, "\0"),
            ]), 512, "\0");
            yield $data;
        }

        yield str_pad(implode('', [
            /* 0 */ 'name' => str_pad('massive_file.txt', 100, "\0"),
            /*100*/ 'mode' => str_pad('', 8, "\0"),
            /*108*/ 'uid' => str_pad('', 8, "\0"),
            /*116*/ 'gid' => str_pad('', 8, "\0"),
            /*100*/ 'size' => str_pad(decoct(min(octdec('77777777777'), $totalSize)), 12, "\0"),
            /*136*/ 'mtime' => str_pad('', 12, "\0"),
            /*148*/ 'chksum' => str_pad('', 8, "\0"),
            /*156*/ 'typeflag' => '0',
            /*157*/ 'linkname' => str_pad('', 100, "\0"),
            /*257*/ 'magic' => "ustar\0",
            /*263*/ 'version' => str_pad('', 2, "\0"),
            /*265*/ 'uname' => str_pad('', 32, "\0"),
            /*297*/ 'gname' => str_pad('', 32, "\0"),
            /*329*/ 'devmajor' => str_pad('', 8, "\0"),
            /*337*/ 'devminor' => str_pad('', 8, "\0"),
            /*345*/ 'prefix' => str_pad('', 155, "\0"),
        ]), 512, "\0");

        $alphabet = range('A', 'Z');
        for ($i = 0; $i < $chunks; $i++) {
            yield str_repeat($alphabet[$i % 26], $chunkSize);
        }

        if ($extra > 0) {
            yield str_repeat('#', $extra);
        }
    }

    public function fileCountProvider()
    {
        return [
            'one' => [1],
            'two' => [2],
            'three' => [3],
        ];
    }
}
