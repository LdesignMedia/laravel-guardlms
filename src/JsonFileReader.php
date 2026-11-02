<?php

namespace GuardLms\Laravel;

use RuntimeException;

final class JsonFileReader
{
    /** Read a bounded local regular file; freshness is checked on the opened file. */
    public function read(string $path, int $maxBytes, ?int $minimumTimestamp = null): array
    {
        if (str_contains($path, "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path)) {
            throw new RuntimeException('Inventory JSON must be a local file path.');
        }
        clearstatcache(true, $path);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Inventory JSON file is missing or unreadable.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Inventory JSON file could not be opened.');
        }
        try {
            $stat = fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('Inventory JSON must be a regular file.');
            }
            if ($minimumTimestamp !== null && $stat['mtime'] < $minimumTimestamp) {
                throw new RuntimeException('Composer outdated report is older than 24 hours.');
            }
            if ($stat['size'] > $maxBytes) {
                throw new RuntimeException('Inventory JSON file exceeds its size limit.');
            }
            $contents = stream_get_contents($handle, $maxBytes + 1);
            if ($contents === false || strlen($contents) > $maxBytes) {
                throw new RuntimeException('Inventory JSON file exceeds its size limit.');
            }
        } finally {
            fclose($handle);
        }
        $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Inventory JSON must contain an object.');
        }

        return $data;
    }
}
