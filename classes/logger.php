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

use bbbext_bnx_datahub\local\helpers\debug_helper;
use stdClass;
use mod_bigbluebuttonbn\instance;

/**
 * Utility class for logging meeting events into a proper data structure.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2025 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class logger {
    /**
     * Logs a meeting with the given instance and data.
     *
     * @param instance $instance An instance of the meeting to be logged.
     * @param stdClass $data     The data associated with the meeting.
     * @return int               The log entry ID or status code.
     */
    public static function log_meeting(instance $instance, stdClass $data): int {
        global $DB;

        // Get the bnx record for this instance.
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $instance->get_instance_id()]);
        if (!$bnx) {
            debug_helper::developer('[bbbext_bnx_datahub] logger::log_meeting No BNX record found for instance.');
            return 0;
        }

        foreach (['meeting_id', 'internal_meeting_id'] as $field) {
            if (
                !isset($data->{$field}) || !is_string($data->{$field}) || $data->{$field} === '' ||
                \core_text::strlen($data->{$field}) > 255
            ) {
                debug_helper::developer('[bbbext_bnx_datahub] logger::log_meeting Invalid meeting identifier.');
                return 0;
            }
        }

        $record = new stdClass();
        $record->bnxid = $bnx->id;
        $record->externalmeetingid = $data->meeting_id;
        $record->internalmeetingid = $data->internal_meeting_id;
        $meetingname = $data->data->metadata->meeting_name ?? null;
        $record->meetingname = \core_text::substr(
            is_string($meetingname) && $meetingname !== '' ? $meetingname : $instance->get_meeting_name(),
            0,
            255
        );
        $start = is_string($data->data->start ?? null) ? strtotime($data->data->start) : false;
        $finish = is_string($data->data->finish ?? null) ? strtotime($data->data->finish) : false;
        $record->timestart = $start === false ? 0 : $start;
        $record->timeend = $finish === false ? 0 : $finish;
        $record->duration = self::meeting_duration($data->data->duration ?? null, $start, $finish);
        $record->metadata = json_encode($data->data->metadata ?? null);
        // Remove the attendees section for privacy reasons.
        if (isset($data->data) && isset($data->data->attendees)) {
            unset($data->data->attendees);
        }
        $record->rawdata = json_encode($data);

        // Define the conditions to identify the record.
        $conditions = ['internalmeetingid' => $record->internalmeetingid];

        // Try to retrieve the existing record.
        $existingrecord = $DB->get_record('bbbext_bnx_datahub_meeting', $conditions);

        if ($existingrecord) {
            if ((int) $existingrecord->bnxid !== (int) $record->bnxid) {
                debug_helper::developer('[bbbext_bnx_datahub] logger::log_meeting Internal meeting belongs to another activity.');
                return 0;
            }
            // Update the existing record. Capture update_record's boolean return separately
            // from the record id so the error gate below catches update failures (OL-3.1.3).
            $record->id = $existingrecord->id;
            $updated = $DB->update_record('bbbext_bnx_datahub_meeting', $record);
            if (!$updated) {
                debug_helper::developer(
                    '[bbbext_bnx_datahub] logger::log_meeting Error during update operation.'
                );
                return 0;
            }
            $r = (int) $existingrecord->id;
        } else {
            // Insert a new record.
            $r = (int) $DB->insert_record('bbbext_bnx_datahub_meeting', $record);
        }

        // Optionally check the result.
        if (!$r) {
            debug_helper::developer('[bbbext_bnx_datahub] logger::log_meeting Error during upsert operation.');
        }

        return $r;
    }

    /**
     * Convert a callback duration to seconds, falling back to valid meeting timestamps.
     *
     * @param mixed $duration Callback duration.
     * @param int|false $start Parsed start timestamp.
     * @param int|false $finish Parsed finish timestamp.
     * @return int Duration in seconds.
     */
    private static function meeting_duration(mixed $duration, int|false $start, int|false $finish): int {
        $maxduration = 2147483647;
        if (is_int($duration) || (is_string($duration) && ctype_digit($duration))) {
            if ($duration >= 0 && (float) $duration <= $maxduration) {
                return (int) $duration;
            }
        } else if (is_string($duration) && preg_match('/^(\d{1,6}):([0-5]\d):([0-5]\d)$/D', $duration, $parts)) {
            $seconds = (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) $parts[3];
            if ($seconds <= $maxduration) {
                return $seconds;
            }
        }

        if ($start !== false && $finish !== false && $finish >= $start && $finish - $start <= $maxduration) {
            return $finish - $start;
        }
        return 0;
    }

    /**
     * Logs a user's participation in a meeting with the given meeting ID and data.
     *
     * @param int      $meetingid The ID of the meeting to log the user's participation.
     * @param stdClass $data      The data associated with the user's participation in the meeting.
     * @return int                The log entry ID or a status code indicating the result.
     */
    public static function log_meeting_user(int $meetingid, stdClass $data): int {
        $result = self::log_meeting_users($meetingid, [$data]);
        return $result['ids'][0] ?? 0;
    }

    /**
     * Batch log a list of attendees against a meeting in O(1) database round-trips
     * for user lookups and existing-row resolution (OL-3.1.2).
     *
     * Per-row writes (update_record / insert_records) remain the only safe way to
     * keep both inserts and updates auditable, but every read - the user fetch and
     * the existing-attendee lookup - is collapsed into a single batch query that
     * is shared by the whole attendee list.
     *
     * @param int        $meetingid Meeting id the attendees belong to.
     * @param stdClass[] $attendees List of attendee payload objects.
     * @return array{ids: int[], skipped: int} Map of stored row ids and skipped count.
     */
    public static function log_meeting_users(int $meetingid, array $attendees): array {
        global $DB;

        $ids = [];
        $skipped = 0;
        if (empty($attendees)) {
            return ['ids' => $ids, 'skipped' => $skipped];
        }

        if ($meetingid <= 0 || !$DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid])) {
            return ['ids' => $ids, 'skipped' => count($attendees)];
        }

        // Filter to numeric ext_user_ids so we can resolve them via core_user
        // without per-row lookups; skip non-Moodle participants such as AI bots.
        $eligible = [];
        $userids = [];
        foreach ($attendees as $attendee) {
            if (!is_object($attendee)) {
                debug_helper::developer('[bbbext_bnx_datahub] logger::log_meeting_users Skipping invalid participant.');
                $skipped++;
                continue;
            }
            $externaluserid = $attendee->ext_user_id ?? null;
            if (
                !(is_int($externaluserid) || (is_string($externaluserid) && ctype_digit($externaluserid))) ||
                $externaluserid <= 0 || (float) $externaluserid >= (float) PHP_INT_MAX
            ) {
                debug_helper::developer('[bbbext_bnx_datahub] logger::log_meeting_users Skipping non-Moodle participant.');
                $skipped++;
                continue;
            }
            $fields = [
                $attendee->duration ?? 0,
                $attendee->score ?? 0,
                $attendee->engagement->chats ?? 0,
                $attendee->engagement->talks ?? 0,
                $attendee->engagement->raisehand ?? 0,
                $attendee->engagement->emojis ?? 0,
                $attendee->engagement->poll_votes ?? 0,
                $attendee->engagement->talk_time ?? 0,
            ];
            $valid = !isset($attendee->engagement) || is_object($attendee->engagement);
            foreach ($fields as $value) {
                if (
                    !(is_int($value) || (is_string($value) && ctype_digit($value))) ||
                    $value < 0 || (float) $value > 2147483647
                ) {
                    $valid = false;
                    break;
                }
            }
            foreach (['joins', 'leaves'] as $field) {
                if (
                    isset($attendee->{$field}) && (!is_array($attendee->{$field}) ||
                    (isset($attendee->{$field}[0]) && !is_string($attendee->{$field}[0])))
                ) {
                    $valid = false;
                } else if (isset($attendee->{$field}[0])) {
                    if (strtotime($attendee->{$field}[0]) === false) {
                        $valid = false;
                    }
                }
            }
            if (!$valid) {
                debug_helper::developer('[bbbext_bnx_datahub] logger::log_meeting_users Skipping invalid participant.');
                $skipped++;
                continue;
            }
            $userid = (int) $attendee->ext_user_id;
            if (isset($userids[$userid])) {
                debug_helper::developer('[bbbext_bnx_datahub] logger::log_meeting_users Skipping duplicate participant.');
                $skipped++;
                continue;
            }
            $eligible[] = ['userid' => $userid, 'data' => $attendee];
            $userids[$userid] = true;
        }

        if (empty($eligible)) {
            return ['ids' => $ids, 'skipped' => $skipped];
        }

        // Single batched fetch for both user lookups and existing attendee rows.
        $userids = array_keys($userids);
        $users = $DB->get_records_list('user', 'id', $userids);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $inparams['meetingid'] = $meetingid;
        $existing = $DB->get_records_select(
            'bbbext_bnx_datahub_meeting_user',
            "meetingid = :meetingid AND userid {$insql}",
            $inparams,
            '',
            'userid, id'
        );

        $newrecords = [];
        foreach ($eligible as $entry) {
            $userid = $entry['userid'];
            $data = $entry['data'];
            $user = $users[$userid] ?? null;
            if ($user === null) {
                debug_helper::developer(
                    '[bbbext_bnx_datahub] logger::log_meeting_users Skipping unknown user (id=' . $userid . ')'
                );
                $skipped++;
                continue;
            }

            $record = new stdClass();
            $record->meetingid = $meetingid;
            $record->userid = $userid;
            $record->fullname = \core_text::substr(fullname($user), 0, 255);
            $moderator = $data->moderator ?? false;
            $record->attendeerole = ($moderator === true || $moderator === 'true') ? 'moderator' : 'viewer';
            $record->timejoin = strtotime($data->joins[0] ?? '');
            $record->timeleave = strtotime($data->leaves[0] ?? '');
            $record->elapsedtime = $data->duration ?? 0;
            $record->attendeescore = $data->score ?? 0;
            $record->engchats = $data->engagement->chats ?? 0;
            $record->engtalks = $data->engagement->talks ?? 0;
            $record->engraisehand = $data->engagement->raisehand ?? 0;
            $record->engemojis = $data->engagement->emojis ?? 0;
            $record->engpollvotes = $data->engagement->poll_votes ?? 0;
            $record->engtalktime = $data->engagement->talk_time ?? 0;
            $record->rawdata = json_encode($data);

            if (isset($existing[$userid])) {
                $record->id = (int) $existing[$userid]->id;
                if ($DB->update_record('bbbext_bnx_datahub_meeting_user', $record)) {
                    $ids[] = $record->id;
                } else {
                    debug_helper::developer(
                        '[bbbext_bnx_datahub] logger::log_meeting_users Error during update operation.'
                    );
                }
            } else {
                $newrecords[] = $record;
            }
        }

        // Insert new attendee rows, collecting each new ID.
        foreach ($newrecords as $newrecord) {
            $ids[] = (int) $DB->insert_record('bbbext_bnx_datahub_meeting_user', $newrecord);
        }

        return ['ids' => $ids, 'skipped' => $skipped];
    }
}
