<?php

declare(strict_types=1);

namespace JakubBoucek\Tar\Tests\Parser;

use JakubBoucek\Tar\StreamReader;
use PHPUnit\Framework\TestCase;

/**
 * Tests that Header::getName() returns the name harvested from the PAX
 * extended header when the file name does not fit into the 100 byte
 * name field of the classic TAR header.
 */
class HeaderPaxNameTest extends TestCase
{
    public function testGetNameReturnsPaxNameWhenLongerThan100Chars(): void
    {
        $name = $this->longName();
        $this->assertGreaterThan(100, strlen($name));

        $stream = fopen('php://temp', 'wb+');
        try {
            $this->assertIsResource($stream);

            fwrite($stream, $this->buildTar($name));
            rewind($stream);

            $reader = new StreamReader($stream);
            $files = [];
            foreach ($reader->getIterator() as $file) {
                $files[] = $file;
            }

            $this->assertCount(1, $files);
            // Without the PAX check in Header::getName() only the first 100
            // characters of the name would be returned
            $this->assertSame($name, $files[0]->getName());
            $this->assertSame(0, $files[0]->getSize());
        } finally {
            fclose($stream);
        }
    }

    /**
     * File name significantly longer than the 100 char classic TAR limit
     */
    private function longName(): string
    {
        $name = 'very/long/file/name/that/fits/nowhere/near/the/100/character/tar/limit';

        return $name . str_repeat('-x', 90);
    }

    /**
     * Builds an in-memory TAR archive holding a single empty file whose name
     * is carried by a PAX extended header
     */
    private function buildTar(string $name): string
    {
        $paxData = $this->buildPaxRecord('name', $name);
        $paxDataBlock = str_pad($paxData, (intdiv(strlen($paxData), 512) + 1) * 512, "\0", STR_PAD_RIGHT);

        $paxHeader = $this->buildTarHeader('././@PaxHeader', strlen($paxData), 'x');
        $fileHeader = $this->buildTarHeader($name, 0, '0');

        return $paxHeader . $paxDataBlock . $fileHeader . str_repeat("\0", 1024);
    }

    /**
     * Builds a 512 byte classic TAR header block
     */
    private function buildTarHeader(string $name, int $size, string $type): string
    {
        $header = str_pad(substr($name, 0, 100), 100, "\0"); // name
        $header .= sprintf('%07o ', 0644);                   // mode
        $header .= sprintf('%07o ', 0);                      // uid
        $header .= sprintf('%07o ', 0);                      // gid
        $header .= sprintf('%011o', $size) . "\0";           // size
        $header .= str_repeat('0', 11) . "\0";               // mtime
        $header .= str_repeat(' ', 8);                       // chksum placeholder
        $header .= $type;                                    // typeflag
        $header .= str_repeat("\0", 100);                    // linkname
        $header .= "ustar\0";                                // magic
        $header .= '00';                                     // version
        $header .= str_repeat("\0", 32);                     // uname
        $header .= str_repeat("\0", 32);                     // gname
        $header .= str_repeat("\0", 8);                      // devmajor
        $header .= str_repeat("\0", 8);                      // devminor
        $header .= str_repeat("\0", 155);                    // prefix
        $header = str_pad($header, 512, "\0", STR_PAD_RIGHT);

        $checksum = 0;
        foreach (str_split($header) as $byte) {
            $checksum += ord($byte);
        }

        return substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
    }

    /**
     * Builds a single PAX record: "<length> <key>=<value>\n"
     */
    private function buildPaxRecord(string $key, string $value): string
    {
        $body = $key . '=' . $value . "\n";
        $length = strlen($body) + 1;
        while (strlen((string)$length) + strlen($body) + 1 !== $length) {
            $length = strlen((string)$length) + strlen($body) + 1;
        }

        return $length . ' ' . $body;
    }
}
