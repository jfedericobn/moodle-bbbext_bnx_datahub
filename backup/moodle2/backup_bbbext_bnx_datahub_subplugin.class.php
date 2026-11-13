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
 * Backup class for bnx_datahub subplugin.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2025 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/backup/moodle2/backup_subplugin.class.php');

/**
 * Backup support for the BNX Data Hub subplugin.
 *
 * @package   bbbext_bnx_datahub
 */
class backup_bbbext_bnx_datahub_subplugin extends backup_subplugin {
    /**
     * Define the subplugin structure to be appended to the activity backup.
     *
     * @return backup_subplugin_element
     */
    protected function define_bigbluebuttonbn_subplugin_structure() {
        // Create XML elements.
        $subplugin = $this->get_subplugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());

        // Meeting elements.
        $meetings = new backup_nested_element('bbbext_bnx_datahub_meetings');
        $meeting = new backup_nested_element('meeting', ['id'], [
            'bnxid',
            'externalmeetingid',
            'internalmeetingid',
            'meetingname',
            'timestart',
            'timeend',
            'duration',
            'excluded',
            'metadata',
            'rawdata',
        ]);

        // Meeting user elements.
        $meetingusers = new backup_nested_element('meeting_users');
        $meetinguser = new backup_nested_element('meeting_user', ['id'], [
            'meetingid',
            'userid',
            'fullname',
            'attendeerole',
            'timejoin',
            'timeleave',
            'elapsedtime',
            'attendeescore',
            'engchats',
            'engtalks',
            'engraisehand',
            'engemojis',
            'engpollvotes',
            'engtalktime',
            'rawdata',
        ]);

        // Build tree.
        $subplugin->add_child($wrapper);
        $wrapper->add_child($meetings);
        $meetings->add_child($meeting);
        $meeting->add_child($meetingusers);
        $meetingusers->add_child($meetinguser);

        // Source: meetings linked via bbbext_bnx.bigbluebuttonbnid = activity id.
        $meetingsql = "SELECT m.id, m.bnxid, m.externalmeetingid, m.internalmeetingid,
                              m.meetingname, m.timestart, m.timeend, m.duration, m.excluded,
                              m.metadata, m.rawdata
                         FROM {bbbext_bnx_datahub_meeting} m
                         JOIN {bbbext_bnx} b ON b.id = m.bnxid
                        WHERE b.bigbluebuttonbnid = ?";
        if ($this->get_setting_value('userinfo') && !$this->get_setting_value('anonymize')) {
            $meeting->set_source_sql($meetingsql, [backup::VAR_PARENTID]);
            $meetinguser->set_source_table('bbbext_bnx_datahub_meeting_user', ['meetingid' => backup::VAR_PARENTID]);
            $meetinguser->annotate_ids('user', 'userid');
        }

        return $subplugin;
    }
}
