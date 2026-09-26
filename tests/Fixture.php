<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test;

use BlueConsole\Style;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class Fixture
{
    /**
     * Groups by content: "same content" x3 (12 B), "other content" x2 (13 B), empty x2,
     * prefix-1/prefix-2 differ only after 7th byte (11 B each), unique.txt alone (7 B).
     */
    public const STANDARD = [
        'a/one.txt' => 'same content',
        'b/two.txt' => 'same content',
        'b/c/three.txt' => 'same content',
        'd/other.txt' => 'other content',
        "h/spaced name ż's.txt" => 'other content',
        'e/unique.txt' => 'unique!',
        'f/empty1' => '',
        'f/empty2' => '',
        'g/prefix-1.bin' => 'PREFIX-AAAA',
        'g/prefix-2.bin' => 'PREFIX-BBBB',
    ];

    public static function dir(): string
    {
        $dir = \sys_get_temp_dir() . '/dd-test-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0777, true);

        return $dir;
    }

    /**
     * @param array<string, string> $files relative path => content
     */
    public static function create(array $files): string
    {
        $dir = self::dir();

        foreach ($files as $path => $content) {
            if (!\is_dir(\dirname("$dir/$path"))) {
                \mkdir(\dirname("$dir/$path"), 0777, true);
            }

            \file_put_contents("$dir/$path", $content);
        }

        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }

        \rmdir($dir);
    }

    /**
     * Order independent form of hash => files map, to compare results of different hashers.
     *
     * @param array<string, string[]> $hashes
     * @return array<string, string[]>
     */
    public static function normalize(array $hashes): array
    {
        foreach ($hashes as &$files) {
            \sort($files);
        }

        \ksort($hashes);

        return $hashes;
    }

    public static function style(BufferedOutput $output): Style
    {
        return new Style(new ArrayInput([]), $output, new FormatterHelper());
    }
}
