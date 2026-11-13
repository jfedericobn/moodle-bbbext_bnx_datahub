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

use advanced_testcase;

/**
 * Tests for orphan attendee cleanup.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2026 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \bbbext_bnx_datahub\local\services\orphan_cleanup_service
 */
final class orphan_cleanup_service_test extends advanced_testcase {
    /**
     * Test preview, explicit cleanup, and preservation of linked attendees.
     */
    public function test_cleanup_requires_explicit_apply(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('bigbluebuttonbn_meetingevents_enabled', 1);
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('bigbluebuttonbn', ['course' => $course->id]);
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $activity->id], '*', MUST_EXIST);
        $meetingid = $DB->insert_record('bbbext_bnx_datahub_meeting', (object) [
            'bnxid' => $bnx->id,
            'externalmeetingid' => 'external-meeting',
            'internalmeetingid' => 'internal-meeting',
            'meetingname' => 'Meeting',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $attendeeids = [];
        foreach ([$meetingid, 0, 987654321] as $attendeemeetingid) {
            $attendeeids[] = $DB->insert_record('bbbext_bnx_datahub_meeting_user', (object) [
                'meetingid' => $attendeemeetingid,
                'userid' => $user->id,
                'fullname' => 'Test user',
                'attendeerole' => 'viewer',
            ]);
        }

        $this->assertSame(2, orphan_cleanup_service::clean());
        $this->assertSame(3, $DB->count_records('bbbext_bnx_datahub_meeting_user'));
        $this->assertSame(2, orphan_cleanup_service::clean(true));
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['id' => $attendeeids[0]]));
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['id' => $attendeeids[1]]));
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['id' => $attendeeids[2]]));
        $this->assertSame(0, orphan_cleanup_service::clean(true));
    }

    /**
     * Test orphan meeting preview and explicit cleanup preserve linked data.
     */
    public function test_orphan_meeting_cleanup_requires_explicit_apply(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('bigbluebuttonbn_meetingevents_enabled', 1);
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('bigbluebuttonbn', ['course' => $course->id]);
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $activity->id], '*', MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();
        $meetings = [];
        $attendees = [];
        foreach ([$bnx->id, 0, 987654321] as $bnxid) {
            $meetingid = $DB->insert_record('bbbext_bnx_datahub_meeting', (object) [
                'bnxid' => $bnxid,
                'externalmeetingid' => 'external-meeting',
                'internalmeetingid' => 'internal-meeting',
                'meetingname' => 'Meeting',
            ]);
            $meetings[] = $meetingid;
            $attendees[] = $DB->insert_record('bbbext_bnx_datahub_meeting_user', (object) [
                'meetingid' => $meetingid,
                'userid' => $user->id,
                'fullname' => 'Test user',
                'attendeerole' => 'viewer',
            ]);
        }

        $this->assertSame(['meetings' => 2, 'attendees' => 2], orphan_cleanup_service::clean_orphan_meetings());
        $this->assertSame(3, $DB->count_records('bbbext_bnx_datahub_meeting'));
        $this->assertSame(3, $DB->count_records('bbbext_bnx_datahub_meeting_user'));
        $this->assertSame(['meetings' => 2, 'attendees' => 2], orphan_cleanup_service::clean_orphan_meetings(true));
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetings[0]]));
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['id' => $attendees[0]]));
        foreach ([1, 2] as $index) {
            $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetings[$index]]));
            $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['id' => $attendees[$index]]));
        }
        $this->assertSame(['meetings' => 0, 'attendees' => 0], orphan_cleanup_service::clean_orphan_meetings(true));
    }
}
