<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace bbbext_bnx_datahub;

/**
 * Event observer callbacks for DataHub.
 *
 * @package    bbbext_bnx_datahub
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /** @var array<int, int[]> BNX records captured before course module deletion. */
    private static array $deletionbnxids = [];

    /**
     * Capture BNX records before the activity and its extensions are deleted.
     *
     * @param \stdClass $cm Course module record.
     */
    public static function pre_course_module_delete(\stdClass $cm): void {
        global $DB;

        if ($DB->get_field('modules', 'name', ['id' => $cm->module]) !== 'bigbluebuttonbn') {
            return;
        }

        self::$deletionbnxids[$cm->id] = $DB->get_fieldset_select(
            'bbbext_bnx',
            'id',
            'bigbluebuttonbnid = :instanceid',
            ['instanceid' => $cm->instance]
        );
    }

    /**
     * Delete analytics after the activity and its recycle-bin backup are processed.
     *
     * @param \core\event\course_module_deleted $event Course module deletion event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        $bnxids = self::$deletionbnxids[$event->objectid] ?? [];
        unset(self::$deletionbnxids[$event->objectid]);
        if (!$bnxids || ($event->other['modulename'] ?? '') !== 'bigbluebuttonbn') {
            return;
        }

        self::delete_analytics($bnxids);
    }

    /**
     * Capture course mappings for synchronous or deferred deletion.
     *
     * @param \stdClass $course Course record.
     */
    public static function pre_course_delete(\stdClass $course): void {
        global $DB;

        $bnxids = $DB->get_fieldset_sql(
            "SELECT bnx.id
               FROM {bbbext_bnx} bnx
               JOIN {bigbluebuttonbn} activity ON activity.id = bnx.bigbluebuttonbnid
              WHERE activity.course = :courseid",
            ['courseid' => $course->id]
        );
        $key = 'pending_course_' . $course->id;
        $previous = get_config('bbbext_bnx_datahub', $key);
        if ($previous !== false && $previous !== '') {
            $bnxids = array_unique(array_merge(explode(',', $previous), $bnxids));
        }
        if ($bnxids) {
            set_config($key, implode(',', $bnxids), 'bbbext_bnx_datahub');
        }
    }

    /**
     * Delete analytics after a course has been deleted.
     *
     * @param \core\event\course_deleted $event Course deletion event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        $key = 'pending_course_' . $event->objectid;
        $value = get_config('bbbext_bnx_datahub', $key);
        if ($value !== false && $value !== '') {
            self::delete_analytics(array_map('intval', explode(',', $value)));
            unset_config($key, 'bbbext_bnx_datahub');
        }
    }

    /**
     * Delete analytics belonging to BNX records.
     *
     * @param int[] $bnxids BNX record IDs.
     */
    private static function delete_analytics(array $bnxids): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        foreach (array_chunk($bnxids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'bnxid');
            $DB->delete_records_select(
                'bbbext_bnx_datahub_meeting_user',
                "meetingid IN (SELECT id FROM {bbbext_bnx_datahub_meeting} WHERE bnxid $insql)",
                $params
            );
            $DB->delete_records_select('bbbext_bnx_datahub_meeting', "bnxid $insql", $params);
        }
        $transaction->allow_commit();
    }

    /**
     * Prevent DataHub from remaining enabled when BNX is unavailable.
     *
     * @param \core\event\config_log_created $event
     * @return void
     */
    public static function config_log_created(\core\event\config_log_created $event): void {
        $other = $event->other ?? [];

        if (
            ($other['name'] ?? '') !== 'disabled'
            || ($other['plugin'] ?? '') !== 'bbbext_bnx_datahub'
            || (int)($other['value'] ?? 0) === 1
        ) {
            return;
        }

        require_once(__DIR__ . '/../db/migration.php');
        if (bbbext_bnx_datahub_is_bnx_enabled()) {
            return;
        }

        bbbext_bnx_datahub_disable_if_bnx_unavailable();
        \core\notification::error(get_string('check_bnx_enabled_enable_error', 'bbbext_bnx_datahub'));
    }

    /**
     * Self-disable DataHub when BNX publishes a disabled transition.
     *
     * DataHub never toggles BNX; it only writes its own disabled flag in
     * response to BNX's public lifecycle event.
     *
     * @param \bbbext_bnx\event\state_changed $event
     * @return void
     */
    public static function bnx_state_changed(\bbbext_bnx\event\state_changed $event): void {
        if (!empty($event->other['enabled'])) {
            return;
        }

        require_once(__DIR__ . '/../db/migration.php');
        bbbext_bnx_datahub_disable_if_bnx_unavailable();
    }
}
