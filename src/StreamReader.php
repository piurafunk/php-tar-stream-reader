<?php

declare(strict_types=1);

namespace JakubBoucek\Tar;

use Iterator;
use IteratorAggregate;
use JakubBoucek\Tar\Exception\EofException;
use JakubBoucek\Tar\Exception\InvalidArchiveFormatException;
use JakubBoucek\Tar\Exception\InvalidArgumentException;
use JakubBoucek\Tar\Exception\RuntimeException;
use JakubBoucek\Tar\Parser\Header;
use JakubBoucek\Tar\Parser\LazyContent;

/**
 * @implements IteratorAggregate<File>
 */
class StreamReader implements IteratorAggregate
{
    /** @var resource */
    private $stream;

    /**
     * @param resource $stream Stream resource of TAR file
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Stream must be a resource');
        }

        $this->stream = $stream;
    }

    /**
     * @return Iterator<int, File>
     */
    public function getIterator(): Iterator
    {
        while (!feof($this->stream)) {
            try {
                $header = $this->readHeader();
            } catch (EofException) {
                return;
            }

            $blockStart = ftell($this->stream);

            if ($blockStart === false) {
                throw new RuntimeException('Unable to get current position of stream');
            }

            if (!$header->isValid()) {
                throw new InvalidArchiveFormatException(
                    sprintf(
                        'Invalid TAR archive format: Invalid Tar header format: at byte %s',
                        $blockStart
                    )
                );
            }

            $contentSize = $header->getSize();
            $contentPadding = ($contentSize % 512) === 0 ? 0 : 512 - ($contentSize % 512);

            // Closure to lazy read, prevents backwards seek or repeated reads of discharged content
            /**
             * @param resource|null $target Resource to external stream to fill it by file content
             * @return resource
             */
            $contentClosure = function ($target = null, ?string $streamProtocol = null) use ($contentSize, $contentPadding, $blockStart) {

                $useTarget = is_resource($target) && get_resource_type($target);
                if ($useTarget) {
                    $stream = $target;
                    $this->copyStream($stream, $contentSize, $blockStart, $contentPadding);
                } elseif ($streamProtocol) {
                    $stream = fopen("{$streamProtocol}://", 'r', context: stream_context_create([
                        $streamProtocol => [
                            'stream' => $this->stream,
                            'size' => $contentSize,
                        ],
                    ]));

                    if (!$stream) {
                        throw new RuntimeException('Unable to create temporary stream.');
                    }
                } else {
                    $stream = fopen('php://temp', 'wb+');

                    if (!$stream) {
                        throw new RuntimeException('Unable to create temporary stream.');
                    }

                    $this->copyStream($stream, $contentSize, $blockStart, $contentPadding);
                }

                return $stream;
            };

            $content = new LazyContent($contentClosure);
            yield new File($header, $content);

            $blockCurrent = ftell($this->stream);
            if ($blockCurrent === false) {
                throw new RuntimeException('Unable to get current position of stream');
            }

            // Ensure we've read past the content
            if ($blockCurrent < $blockStart + $contentSize) {
                $bytes = fseek($this->stream, $blockStart + $contentSize - $blockCurrent, SEEK_CUR);
                if ($bytes === -1) {
                    throw new InvalidArchiveFormatException(
                        sprintf(
                            'Invalid TAR archive format: Unexpected end of file at position: %s, expected %d bytes of content',
                            $blockStart,
                            $contentSize,
                        )
                    );
                }

                $blockCurrent = ftell($this->stream);
                if ($blockCurrent === false) {
                    throw new RuntimeException('Unable to get current position of stream');
                }
            }

            // Ensure we've read past the padding
            if ($blockCurrent < $blockStart + $contentSize + $contentPadding) {
                $bytes = fseek($this->stream, $blockStart + $contentSize + $contentPadding - $blockCurrent, SEEK_CUR);
                if ($bytes === -1) {
                    throw new InvalidArchiveFormatException(
                        sprintf(
                            'Invalid TAR archive format: Unexpected end of file at position: %s, expected %d bytes of block padding',
                            $blockStart,
                            $contentPadding,
                        )
                    );
                }
            }

            // Ensure the stream is closed
            $content->close();
        }
    }

    private function readHeader(): Header
    {
        do {
            $header = fread($this->stream, 512);

            if ($header === '') {
                throw new EofException();
            }

            if ($header === false || strlen($header) < 512) {
                throw new InvalidArchiveFormatException(
                    sprintf(
                        'Invalid TAR archive format: Unexpected end of file, returned non-block size: %d bytes',
                        $header === false ? 0 : strlen($header)
                    )
                );
            }
        } while (self::isNullFilled($header));
        // ↑↑↑ TAR format inserts few blocks of nulls to EOF - just skip it

        $header = new Header($header);

        // Handle PAX header block
        $paxHeader = null;
        switch($header->getType()) {
            case 'x':
                $paxHeader = $header;
                $paxData = fread($this->stream, $paxHeader->getSize()); // @phpstan-ignore argument.type
                if ($paxData === false) {
                    throw new InvalidArchiveFormatException(
                        'Invalid TAR archive format: Unexpected end of file, expected PAX header data'
                    );
                }
                fseek($this->stream, 512 - ($paxHeader->getSize() % 512), SEEK_CUR); // Skip null byte padding
                $paxHeader->harvestPaxData($paxData);
                $header = $this->readHeader();
                break;
        }

        if ($paxHeader) {
            $header->mergePaxHeader($paxHeader);
        }

        return $header;
    }

    private static function isNullFilled(string $string): bool
    {
        return trim($string, "\0") === '';
    }

    /**
     * @param resource $stream
     * @param int $contentSize
     * @param int $blockStart
     * @param int $contentPadding
     * @return void
     */
    protected function copyStream($stream, int $contentSize, int $blockStart, int $contentPadding): void
    {
        fseek($stream, 0);
        $bytes = stream_copy_to_stream($this->stream, $stream, $contentSize);

        if ($bytes !== $contentSize) {
            throw new InvalidArchiveFormatException(
                sprintf(
                    'Invalid TAR archive format: Unexpected end of file at position: %s, expected %d bytes, only %d bytes read',
                    $blockStart,
                    $contentSize,
                    ($bytes ?: 0)
                )
            );
        }

        if ($contentPadding) {
            // Skip padding
            $bytes = fseek($this->stream, $contentPadding, SEEK_CUR);

            if ($bytes === -1) {
                throw new InvalidArchiveFormatException(
                    sprintf(
                        'Invalid TAR archive format: Unexpected end of file at position: %s, expected %d bytes of block padding',
                        $blockStart,
                        $contentPadding,
                    )
                );
            }
        }
    }
}
