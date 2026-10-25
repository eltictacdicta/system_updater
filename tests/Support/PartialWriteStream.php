<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\SystemUpdater\Support;

/**
 * A stream wrapper that makes the atomic write's two hardest claims observable
 * without racing the writer.
 *
 * {@see \recovery_secret_store()} has to create the file owner-only and treat a
 * write that stopped short as a failure that leaves nothing behind. On a real
 * filesystem neither is observable from outside the call:
 *
 * - the mode that matters is the one the file is *created* with. The `chmod()`
 *   that follows is the fix for a permissive umask, so by the time the call
 *   returns both a buggy and a correct implementation report `0600`, and a
 *   filesystem race is the only way to see the difference;
 * - a short `file_put_contents()` needs a nearly full disk to happen for real.
 *
 * A wrapper sits inside both. `stream_open()` runs while the library's umask
 * block is in force, so reading the process umask there reports the mask the
 * file was created under. `stream_write()` may accept fewer bytes than it is
 * handed and then refuse, which is how PHP reports a partial write:
 * `file_put_contents()` returns `false` with the accepted prefix already on
 * disk — the exact state that must not survive.
 *
 * The virtual tree is deliberately tiny: one writable directory
 * (`SCHEME://store`) and whatever is opened inside it. Nothing here touches the
 * real filesystem, and the scheme is namespaced so it cannot collide.
 */
final class PartialWriteStream
{
    public const SCHEME = 'su-partial-write';

    /** The umask in force when the last stream was created, or `-1`. */
    public static int $umaskAtCreation = -1;

    /** How many bytes `stream_write()` accepts before it refuses. */
    public static int $acceptedBytes = 0;

    /** How many bytes the last stream accepted in total. */
    public static int $bytesWritten = 0;

    /** The last path handed to `stream_open()`. */
    public static string $openedPath = '';

    /** The virtual files that currently exist, as `path => contents`. */
    private static array $files = [];

    /** @var resource|null assigned by PHP when the wrapper is opened */
    public $context;

    /** Bytes accepted from this stream, against {@see self::$acceptedBytes}. */
    private int $written = 0;

    /**
     * Register the scheme (once per process) and forget everything the
     * previous case observed.
     */
    public static function reset(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        self::$umaskAtCreation = -1;
        self::$acceptedBytes = 0;
        self::$bytesWritten = 0;
        self::$openedPath = '';
        self::$files = [];
    }

    /** A target path inside the virtual, writable directory. */
    public static function target(): string
    {
        return self::SCHEME . '://store/' . RecoverySecretFixture::SECRET_FILENAME;
    }

    public static function exists(string $path): bool
    {
        return array_key_exists($path, self::$files);
    }

    public static function contentsOf(string $path): string
    {
        return self::$files[$path] ?? '';
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$umaskAtCreation = umask();
        self::$openedPath = $path;
        self::$bytesWritten = 0;
        self::$files[$path] = '';
        $this->written = 0;

        return true;
    }

    public function stream_write(string $data): int
    {
        $remaining = self::$acceptedBytes - $this->written;
        if ($remaining <= 0) {
            return 0;
        }

        $chunk = substr($data, 0, $remaining);
        self::$files[self::$openedPath] .= $chunk;
        $this->written += strlen($chunk);
        self::$bytesWritten += strlen($chunk);

        return strlen($chunk);
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_stat(): array|false
    {
        return self::fileStat(strlen(self::$files[self::$openedPath] ?? ''));
    }

    public function url_stat(string $path, int $flags): array|false
    {
        if (rtrim($path, '/') === self::SCHEME . '://store') {
            return self::directoryStat();
        }

        if (array_key_exists($path, self::$files)) {
            return self::fileStat(strlen(self::$files[$path]));
        }

        return false;
    }

    public function unlink(string $path): bool
    {
        if (!array_key_exists($path, self::$files)) {
            return false;
        }

        unset(self::$files[$path]);

        return true;
    }

    public function rename(string $from, string $to): bool
    {
        if (!array_key_exists($from, self::$files)) {
            return false;
        }

        self::$files[$to] = self::$files[$from];
        unset(self::$files[$from]);

        return true;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return array_key_exists($path, self::$files);
    }

    private static function directoryStat(): array
    {
        return self::stat(040777, 0);
    }

    private static function fileStat(int $size): array
    {
        return self::stat(0100600, $size);
    }

    /**
     * A stat array PHP accepts, in both its string and numeric shapes.
     */
    private static function stat(int $mode, int $size): array
    {
        $now = time();

        $stat = [
            'dev' => 0,
            'ino' => 0,
            'mode' => $mode,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => $size,
            'atime' => $now,
            'mtime' => $now,
            'ctime' => $now,
            'blksize' => -1,
            'blocks' => -1,
        ];

        $index = 0;
        foreach ($stat as $value) {
            $stat[$index++] = $value;
        }

        return $stat;
    }
}
