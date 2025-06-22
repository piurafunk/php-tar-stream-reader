<?php

namespace JakubBoucek\Tar;

class ReferencedStreamWrapper
{
    /** @var resource */
    public $context;
    /** @var resource */
    protected $stream;
    protected int $size = 0;
    protected int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $options = stream_context_get_options($this->context);
        if (!array_key_exists('referenced', $options)) {
            return false;
        }
        $options = $options['referenced'];

        if (!array_key_exists('stream', $options)) {
            return false;
        }
        $this->stream = $options['stream'];

        if (!is_resource($this->stream)) {
            return false;
        }

        if (!array_key_exists('size', $options)) {
            return false;
        }
        $this->size = $options['size'];

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $min = min($count, $this->size - $this->position);
        assert($min >= 0);
        if ($min == 0) {
            return '';
        }
        $data = fread($this->stream, $min);

        if ($data === false) {
            return false;
        }

        $this->position += strlen($data);
        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->position >= $this->size;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_stat(): array
    {
        return ['size' => $this->size];
    }
}

stream_wrapper_register('referenced', ReferencedStreamWrapper::class)
|| throw new \RuntimeException('Failed to register stream wrapper ' . ReferencedStreamWrapper::class);
