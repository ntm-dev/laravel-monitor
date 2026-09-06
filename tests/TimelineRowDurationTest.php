<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Support\TimelineEntry;
use LaravelMonitor\View\Components\Requests\TimelineRow;

/**
 * A job track's root row shows the sum of its attempts' durations. That sum is
 * a float of milliseconds, so the property holding it has to be one too — as
 * an ?int it silently truncated, showing "25ms" above an attempt reading
 * "25.62ms" for the very same single-attempt job.
 */
class TimelineRowDurationTest extends TestCase
{
    // Not touched here, but TestCase::setUp() purges storage on every test.
    use RefreshDatabase;

    public function test_a_job_track_root_keeps_fractional_milliseconds(): void
    {
        $row = $this->rowWithAttemptsDuration(25.62);

        $this->assertSame('25.62ms', $row->durationLabel);
    }

    public function test_a_single_attempt_track_matches_that_attempts_own_label(): void
    {
        $attempt = $this->row($this->entry(25.62));
        $track = $this->rowWithAttemptsDuration(25.62);

        $this->assertSame($attempt->durationLabel, $track->durationLabel);
    }

    public function test_whole_millisecond_sums_are_unchanged(): void
    {
        $this->assertSame('40ms', $this->rowWithAttemptsDuration(40.0)->durationLabel);
    }

    protected function rowWithAttemptsDuration(float $attemptsDuration): TimelineRow
    {
        // The bounding box (entry duration) deliberately differs from the sum:
        // it spans idle retry-wait too, so this proves the label reads the sum.
        return $this->row($this->entry(999.0), $attemptsDuration);
    }

    protected function row(TimelineEntry $entry, ?float $attemptsDuration = null): TimelineRow
    {
        return new TimelineRow(
            entry: $entry,
            left: 0.0,
            width: 1.0,
            trackId: 'job-1',
            kind: 'root',
            attemptsDuration: $attemptsDuration,
        );
    }

    protected function entry(float $duration): TimelineEntry
    {
        return new TimelineEntry(
            id: 'job-1:request',
            type: 'request',
            label: 'App\\Jobs\\SendWelcomeEmail',
            start: 0,
            duration: $duration,
        );
    }
}
