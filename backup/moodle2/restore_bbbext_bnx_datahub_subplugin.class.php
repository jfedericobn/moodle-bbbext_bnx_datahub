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

/**
 * Restore subplugin class for bnx_datahub.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2025 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/backup/moodle2/restore_subplugin.class.php');

/**
 * Restore support for the BNX Data Hub subplugin.
 *
 * @package   bbbext_bnx_datahub
 */
class restore_bbbext_bnx_datahub_subplugin extends restore_subplugin {
    /** @var array<int, bool> Meetings with at least one unmapped attendee. */
    private array $unmappedmeetings = [];

    /**
     * Declare paths handled by this subplugin during restore.
     *
     * @return restore_path_element[]
     */
    protected function define_bigbluebuttonbn_subplugin_structure() {
        $paths = [];
        if (!$this->get_setting_value('userinfo') || !empty($this->task->get_info()->root_settings['anonymize'])) {
            return $paths;
        }
        $paths[] = new restore_path_element(
            'datahub_meeting',
            $this->get_pathfor('/bbbext_bnx_datahub_meetings/meeting')
        );
        $paths[] = new restore_path_element(
            'datahub_meeting_user',
            $this->get_pathfor('/bbbext_bnx_datahub_meetings/meeting/meeting_users/meeting_user')
        );
        return $paths;
    }

    /**
     * Process a backed up meeting record and insert it mapping the bnx id.
     *
     * @param array $data
     * @return void
     */
    public function process_datahub_meeting($data) {
        global $DB;
        $data = (object)$data;

        // Map original bnx id to restored bnx id.
        $newbnxid = $this->get_mappingid('bbbext_bnx', $data->bnxid);
        if (empty($newbnxid)) {
            // If parent BNX record wasn't restored, skip meeting.
            return;
        }

        $oldid = $data->id;

        $record = new stdClass();
        $record->bnxid = $newbnxid;
        $record->externalmeetingid = $data->externalmeetingid;
        $record->internalmeetingid = $data->internalmeetingid;
        $record->meetingname = $data->meetingname;
        $record->timestart = $data->timestart;
        $record->timeend = $data->timeend;
        $record->duration = $data->duration;
        $record->excluded = $data->excluded ?? 0;
        $record->metadata = $data->metadata ?? null;
        $record->rawdata = $data->rawdata ?? null;

        $newid = $DB->insert_record('bbbext_bnx_datahub_meeting', $record);

        // Save mapping for meeting user restore.
        $this->set_mapping('bbbext_bnx_datahub_meeting', $oldid, $newid);
    }

    /**
     * Process a backed up meeting user record.
     *
     * @param array $data
     * @return void
     */
    public function process_datahub_meeting_user($data) {
        global $DB;
        $data = (object)$data;

        // Map original meeting id to restored meeting id.
        $newmeetingid = $this->get_mappingid('bbbext_bnx_datahub_meeting', $data->meetingid);
        if (empty($newmeetingid)) {
            // If parent meeting record wasn't restored, skip.
            return;
        }

        // Map user id if present.
        $newuserid = null;
        if (!empty($data->userid)) {
            $newuserid = $this->get_mappingid('user', $data->userid);
        }
        if (empty($newuserid)) {
            if (empty($this->unmappedmeetings[$newmeetingid])) {
                $this->unmappedmeetings[$newmeetingid] = true;
                $DB->update_record('bbbext_bnx_datahub_meeting', (object) [
                    'id' => $newmeetingid,
                    'metadata' => null,
                    'rawdata' => null,
                ]);
                $DB->set_field('bbbext_bnx_datahub_meeting_user', 'rawdata', null, ['meetingid' => $newmeetingid]);
            }
            return;
        }

        $record = new stdClass();
        $record->meetingid = $newmeetingid;
        $record->userid = $newuserid;
        $record->fullname = $data->fullname;
        $record->attendeerole = $data->attendeerole ?? $data->role ?? 'viewer';
        $record->timejoin = $data->timejoin;
        $record->timeleave = $data->timeleave;
        $record->elapsedtime = $data->elapsedtime;
        $record->attendeescore = $data->attendeescore ?? $data->score ?? 0;
        $record->engchats = $data->engchats;
        $record->engtalks = $data->engtalks;
        $record->engraisehand = $data->engraisehand;
        $record->engemojis = $data->engemojis;
        $record->engpollvotes = $data->engpollvotes;
        $record->engtalktime = $data->engtalktime;
        $record->rawdata = empty($this->unmappedmeetings[$newmeetingid]) ? ($data->rawdata ?? null) : null;

        $DB->insert_record('bbbext_bnx_datahub_meeting_user', $record);
    }
}
