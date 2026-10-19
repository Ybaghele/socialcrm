<?php

namespace App\Services\Automations;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The workspace's opening hours, used by "outside business hours" automations.
 *
 * Stored in workspace.settings so one definition serves every rule (an away
 * message and an after-hours routing rule must agree on what "closed" means).
 * Defaults to Mon-Sat, 10:00-19:00, Asia/Kolkata.
 *
 * Days use Carbon's numbering: 0 = Sunday … 6 = Saturday.
 */
class BusinessHours
{
    /** @var array{timezone: string, days: array<int, int>, start: string, end: string} */
    public const DEFAULTS = [
        'timezone' => 'Asia/Kolkata',
        'days' => [1, 2, 3, 4, 5, 6],
        'start' => '10:00',
        'end' => '19:00',
    ];

    public const SETTINGS_KEY = 'automation_business_hours';

    /**
     * @return array{timezone: string, days: array<int, int>, start: string, end: string}
     */
    public function for(Workspace $workspace): array
    {
        $stored = ($workspace->settings ?? [])[self::SETTINGS_KEY] ?? [];

        if (! is_array($stored)) {
            $stored = [];
        }

        $hours = array_merge(self::DEFAULTS, $stored);

        // Never hand Carbon a timezone it will reject.
        if (! is_string($hours['timezone']) || ! in_array($hours['timezone'], timezone_identifiers_list(), true)) {
            $hours['timezone'] = self::DEFAULTS['timezone'];
        }

        $hours['days'] = array_values(array_unique(array_map('intval', (array) $hours['days'])));
        $hours['start'] = is_string($hours['start']) && $this->minutes($hours['start']) !== null ? $hours['start'] : self::DEFAULTS['start'];
        $hours['end'] = is_string($hours['end']) && $this->minutes($hours['end']) !== null ? $hours['end'] : self::DEFAULTS['end'];

        return $hours;
    }

    /** Is the workspace open at the given moment (defaults to now)? */
    public function isOpen(Workspace $workspace, ?CarbonInterface $at = null): bool
    {
        $hours = $this->for($workspace);

        $now = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $now = $now->setTimezone($hours['timezone']);

        if (! in_array($now->dayOfWeek, $hours['days'], true)) {
            return false;
        }

        $start = $this->minutes($hours['start']);
        $end = $this->minutes($hours['end']);

        if ($start === null || $end === null) {
            return true; // misconfigured — never treat the workspace as closed
        }

        $nowMinutes = $now->hour * 60 + $now->minute;

        // A window that ends before it starts (e.g. 20:00–04:00) wraps midnight.
        return $start <= $end
            ? $nowMinutes >= $start && $nowMinutes < $end
            : $nowMinutes >= $start || $nowMinutes < $end;
    }

    /** "19:00" => 1140. Returns null when the string is not a clock time. */
    private function minutes(string $time): ?int
    {
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($time), $match)) {
            return null;
        }

        return ((int) $match[1]) * 60 + (int) $match[2];
    }
}
