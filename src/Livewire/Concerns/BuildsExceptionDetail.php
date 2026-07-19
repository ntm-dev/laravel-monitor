<?php

namespace LaravelMonitor\Livewire\Concerns;

use Illuminate\Support\Collection;

/**
 * Shape a fetched exception's raw payload (frames, message, class, ...)
 * into what `exception-detail.blade.php` renders: grouped/numbered stack
 * frames with their source snippets, the "Copy as Markdown" text, and the
 * labelled Summary rows. Pure data transformation — no storage/HTTP
 * dependency — so it's shared by both the tab-based `ExceptionDetail`
 * Livewire card and the standalone `/monitor/issues/{uuid}` page
 * (`Http\Controllers\IssueController`), which fetch the underlying data
 * differently (period-scoped vs. all-time) but shape it identically.
 */
trait BuildsExceptionDetail
{
    /**
     * Build the labelled Summary rows shown in the metadata card.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function summary(
        ?object $lastSeen,
        ?object $firstSeen,
        ?string $phpVersion,
        ?string $laravelVersion,
        int $impactedUsers,
        int $occurrencesCount,
        Collection $servers,
        string $tz,
    ): array {
        return [
            ['Last Seen', $lastSeen ? \LaravelMonitor\Support\Format::datetime($lastSeen).' '.$tz : '—'],
            ['First Seen', $firstSeen ? \LaravelMonitor\Support\Format::datetime($firstSeen).' '.$tz : '—'],
            ['First Reported In', $laravelVersion ? 'Laravel '.$laravelVersion : '—'],
            ['PHP Version', $phpVersion ?? '—'],
            ['Laravel Version', $laravelVersion ?? '—'],
            ['Impacted Users', number_format($impactedUsers)],
            ['Occurrences', number_format($occurrencesCount)],
            ['Servers', $servers->isNotEmpty() ? $servers->implode(', ') : '—'],
        ];
    }

    /**
     * Group frames the way the trace view renders them: consecutive vendor
     * frames collapse into one block, and each frame carries its ready-to-print
     * source lines so the Blade view stays free of logic.
     *
     * @param  array<int, array<string, mixed>>  $frames
     * @return array<int, array{vendor: bool, count: int, frames: array<int, array<string, mixed>>}>
     */
    protected function frameGroups(array $frames): array
    {
        $defaultChosen = false;
        $groups = [];

        foreach ($frames as $frame) {
            $vendor = (bool) ($frame['vendor'] ?? false);

            // Open the first application frame by default, like Ignition.
            $main = ! $defaultChosen && ! $vendor;
            $defaultChosen = $defaultChosen || $main;

            $prepared = $this->prepareFrame($frame, $vendor, $main);
            $last = $groups[count($groups) - 1] ?? null;

            if ($vendor && $last && $last['vendor']) {
                $groups[count($groups) - 1]['frames'][] = $prepared;
                $groups[count($groups) - 1]['count']++;
            } else {
                $groups[] = ['vendor' => $vendor, 'count' => 1, 'frames' => [$prepared]];
            }
        }

        return $groups;
    }

    /**
     * Normalize a single frame and attach its numbered source lines (from the
     * stored snippet, or read from disk for real application frames).
     *
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed>
     */
    protected function prepareFrame(array $frame, bool $vendor, bool $main): array
    {
        $line = (int) ($frame['line'] ?? 0);
        $code = $frame['code'] ?? null;
        $start = $frame['start_line'] ?? 1;

        if (empty($code)) {
            [$code, $start] = $this->readSource($frame['file'] ?? '', $line);
        }

        $lines = $this->sourceLines($code, (int) $start, $line);

        return [
            'label' => $frame['label'] ?? $frame['function'] ?? '{main}',
            'file' => $frame['file'] ?? '[internal]',
            'line' => $line,
            'vendor' => $vendor,
            'main' => $main,
            'has_code' => $lines !== [],
            'lines' => $lines,
        ];
    }

    /**
     * Turn a source snippet into numbered lines flagged with the error line.
     *
     * @return array<int, array{number: int, code: string, error: bool}>
     */
    protected function sourceLines(?string $code, int $start, int $errorLine): array
    {
        if ($code === null || $code === '') {
            return [];
        }

        $lines = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $code)) as $index => $text) {
            $number = $start + $index;
            $lines[] = ['number' => $number, 'code' => $text, 'error' => $number === $errorLine];
        }

        return $lines;
    }

    /**
     * Read a window of source lines around $line from a project-relative path,
     * when the file is actually present on disk.
     *
     * @return array{0: string|null, 1: int|null}
     */
    protected function readSource(string $relative, int $line): array
    {
        if ($relative === '' || $line < 1 || str_contains($relative, '[internal]')) {
            return [null, null];
        }

        $path = base_path($relative);

        if (! is_file($path) || ! is_readable($path)) {
            return [null, null];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [null, null];
        }

        $start = max(1, $line - 5);
        $end = min(count($lines), $line + 5);

        return [implode("\n", array_slice($lines, $start - 1, $end - $start + 1)), $start];
    }

    protected function markdown(array $payload, bool $handled): string
    {
        $lines = [
            '## '.($payload['class'] ?? 'Exception'),
            '',
            '- **Status:** '.($handled ? 'Handled' : 'Unhandled'),
        ];

        if (! empty($payload['message'])) {
            $lines[] = '- **Message:** '.$payload['message'];
        }

        if (! empty($payload['file'])) {
            $lines[] = '- **Location:** `'.$payload['file'].':'.($payload['line'] ?? 0).'`';
        }

        if (! empty($payload['php_version'])) {
            $lines[] = '- **PHP:** '.$payload['php_version'];
        }

        if (! empty($payload['laravel_version'])) {
            $lines[] = '- **Laravel:** '.$payload['laravel_version'];
        }

        $frames = $payload['frames'] ?? [];

        if ($frames !== []) {
            $lines[] = '';
            $lines[] = '### Stack trace';
            $lines[] = '```';

            foreach ($frames as $frame) {
                $lines[] = ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? 0).'  '.($frame['label'] ?? $frame['function'] ?? '');
            }

            $lines[] = '```';
        }

        return implode("\n", $lines);
    }
}
