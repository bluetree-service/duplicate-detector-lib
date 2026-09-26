<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Report;

use BlueData\Data\Formats;
use BlueDuplicateDetector\DuplicateGroup;

final class HtmlReport
{
    public function __construct(private readonly int $pageSize = 100)
    {
    }

    /**
     * @param DuplicateGroup[] $groups in display order (Grouper output)
     * @return int number of written pages
     * @throws \RuntimeException
     */
    public function save(array $groups, string $dir): int
    {
        if (!\is_dir($dir) && !@\mkdir($dir, 0777, true)) {
            throw new \RuntimeException("Unable to create HTML report directory: $dir");
        }

        foreach (\glob("$dir/duplicates-*.html") ?: [] as $oldPage) {
            @\unlink($oldPage);
        }

        $pages = \array_chunk($groups, $this->pageSize);
        $pagesCount = \count($pages);
        $totalFiles = 0;
        $totalSize = 0;
        $indexRows = '';

        foreach ($pages as $index => $pageGroups) {
            $page = $index + 1;
            $body = '';
            $pageSize = 0;

            foreach ($pageGroups as $groupIndex => $group) {
                $number = $index * $this->pageSize + $groupIndex + 1;
                $rows = '';

                foreach ($group->files as $file) {
                    $rows .= '<tr><td>' . \htmlspecialchars($file) . '</td><td class="size">'
                        . Formats::dataSize($group->sizes[$file]) . '</td></tr>';
                }

                $pageSize += $group->size;
                $totalFiles += \count($group->files);
                $body .= "<h2>#$number <small>" . \htmlspecialchars($group->key) . '</small></h2>'
                    . "<table>$rows</table>";
            }

            $totalSize += $pageSize;
            $nav = '<p class="nav"><a href="index.html">index</a>'
                . ($page > 1 ? ' | <a href="' . self::pageName($page - 1) . '">&lsaquo; previous</a>' : '')
                . " | page $page / $pagesCount"
                . ($page < $pagesCount ? ' | <a href="' . self::pageName($page + 1) . '">next &rsaquo;</a>' : '')
                . '</p>';

            $this->write("$dir/" . self::pageName($page), "Duplicated files - page $page", $nav . $body . $nav);

            $firstDir = \htmlspecialchars(\dirname(\reset($pageGroups)->files[0]));
            $lastDir = \htmlspecialchars(\dirname(\end($pageGroups)->files[0]));
            $indexRows .= '<tr><td><a href="' . self::pageName($page) . "\">page $page</a></td>"
                . "<td>$firstDir<br>$lastDir</td><td class=\"size\">" . Formats::dataSize($pageSize) . '</td></tr>';
        }

        $summary = '<p>Duplications: <b>' . \count($groups) . "</b>, duplicated files: <b>$totalFiles</b>, size: <b>"
            . Formats::dataSize($totalSize) . '</b>, generated: ' . \date('Y-m-d H:i:s') . '</p>';

        $this->write("$dir/index.html", 'Duplicated files', "$summary<table>$indexRows</table>");

        return $pagesCount;
    }

    private static function pageName(int $page): string
    {
        return \sprintf('duplicates-%04d.html', $page);
    }

    /**
     * @throws \RuntimeException
     */
    private function write(string $path, string $title, string $body): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>$title</title>
<style>
:root { color-scheme: dark; }
body { font-family: sans-serif; margin: 2em; background: #1e1f22; color: #d4d4d4; }
b { color: #fff; }
h2 { font-size: 1em; margin: 1.5em 0 .3em; }
h2 small { color: #8a8a8a; font-weight: normal; font-family: monospace; }
table { border-collapse: collapse; width: 100%; }
td { border: 1px solid #3a3b3f; padding: .3em .6em; font-family: monospace; word-break: break-all; }
td.size { width: 8em; text-align: right; white-space: nowrap; color: #9cdcfe; }
tr:nth-child(even) { background: #26272b; }
a { color: #6cb6ff; }
.nav { font-size: 1.1em; }
</style>
</head>
<body>
<h1>$title</h1>
$body
</body>
</html>
HTML;

        if (@\file_put_contents($path, $html) === false) {
            throw new \RuntimeException("Unable to save HTML report: $path");
        }
    }
}
