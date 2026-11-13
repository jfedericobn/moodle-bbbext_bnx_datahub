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

namespace bbbext_bnx_datahub\local\services;

/**
 * Previews or removes orphaned analytics records.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2026 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orphan_cleanup_service {
    /** @var string Condition matching attendee records without a meeting. */
    private const ORPHAN_CONDITION = 'meetingid NOT IN (SELECT id FROM {bbbext_bnx_datahub_meeting})';

    /** @var string Condition matching meetings without a BNX record. */
    private const ORPHAN_MEETING_CONDITION = 'bnxid NOT IN (SELECT id FROM {bbbext_bnx})';

    /**
     * Count orphan attendees and remove them only when requested.
     *
     * @param bool $apply Whether to delete the orphan attendees.
     * @return int Number of orphans found before any deletion.
     */
    public static function clean(bool $apply = false): int {
        global $DB;

        $count = $DB->count_records_select('bbbext_bnx_datahub_meeting_user', self::ORPHAN_CONDITION);
        if ($apply && $count > 0) {
            $DB->delete_records_select('bbbext_bnx_datahub_meeting_user', self::ORPHAN_CONDITION);
        }

        return $count;
    }

    /**
     * Count and optionally delete meetings without a BNX record and their attendees.
     *
     * @param bool $apply Whether to delete the orphan meetings and attendees.
     * @return array{meetings: int, attendees: int} Counts before deletion.
     */
    public static function clean_orphan_meetings(bool $apply = false): array {
        global $DB;

        $attendeecondition = 'meetingid IN (SELECT id FROM {bbbext_bnx_datahub_meeting} WHERE '
            . self::ORPHAN_MEETING_CONDITION . ')';
        $transaction = $DB->start_delegated_transaction();
        $counts = [
            'meetings' => $DB->count_records_select('bbbext_bnx_datahub_meeting', self::ORPHAN_MEETING_CONDITION),
            'attendees' => $DB->count_records_select('bbbext_bnx_datahub_meeting_user', $attendeecondition),
        ];
        if ($apply && $counts['meetings'] > 0) {
            $DB->delete_records_select('bbbext_bnx_datahub_meeting_user', $attendeecondition);
            $DB->delete_records_select('bbbext_bnx_datahub_meeting', self::ORPHAN_MEETING_CONDITION);
        }
        $transaction->allow_commit();
        return $counts;
    }
}
