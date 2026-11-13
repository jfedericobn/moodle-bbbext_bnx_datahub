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

namespace bbbext_bnx_datahub\bigbluebuttonbn;

use advanced_testcase;
use mod_bigbluebuttonbn\instance;
use stdClass;

/**
 * Tests for the broker_meeting_events_addons class.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2025 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \bbbext_bnx_datahub\bigbluebuttonbn\broker_meeting_events_addons
 */
final class broker_meeting_events_addons_test extends advanced_testcase {
    /**
     * Set up for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Sidecar-contract bootstrap (CC-2).
        set_config('bigbluebuttonbn_meetingevents_enabled', 1);
        set_config('enable_debug_logging', 1, 'bbbext_bnx_datahub');
    }

    /**
     * Enable the BNX plugin for testing.
     */
    private function enable_bnx_plugin(): void {
        // Enable the bnx plugin by setting version.
        set_config('version', '2025010100', 'bbbext_bnx');
        unset_config('disabled', 'bbbext_bnx');
        \core_plugin_manager::reset_caches();
    }

    /**
     * Disable the BNX plugin for testing.
     */
    private function disable_bnx_plugin(): void {
        set_config('disabled', 1, 'bbbext_bnx');
        \core_plugin_manager::reset_caches();
    }

    /**
     * Create a BigBlueButton activity with BNX record.
     *
     * @return array Array containing course, bbb instance, and instance object.
     */
    private function create_bbb_with_bnx(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $bbb = $this->getDataGenerator()->create_module('bigbluebuttonbn', [
            'course' => $course->id,
            'name' => 'Events Test Meeting',
        ]);

        // Check if BNX record already exists (created by mod_instance_helper hook).
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $bbb->id]);
        if (!$bnx) {
            // Create BNX record only if it doesn't exist.
            $bnx = new stdClass();
            $bnx->bigbluebuttonbnid = $bbb->id;
            $bnx->timecreated = time();
            $bnx->timemodified = time();
            $bnx->id = $DB->insert_record('bbbext_bnx', $bnx);
        }

        $instance = instance::get_from_instanceid($bbb->id);

        return [$course, $bbb, $instance];
    }

    /**
     * Create meeting events JSON data.
     *
     * @param array $attendees Array of attendee data.
     * @return string JSON string.
     */
    private function create_meeting_events_json(array $attendees = []): string {
        $data = [
            'meeting_id' => 'ext-meeting-events-test',
            'internal_meeting_id' => 'int-meeting-events-test',
            'data' => [
                'metadata' => [
                    'meeting_name' => 'Events Test Meeting',
                ],
                'start' => '2026-01-01T10:00:00Z',
                'finish' => '2026-01-01T11:00:00Z',
                'duration' => 3600,
                'attendees' => $attendees,
            ],
        ];

        return json_encode($data);
    }

    /**
     * Create attendee data for events.
     *
     * @param int $userid User ID.
     * @param bool $ismoderator Whether the user is a moderator.
     * @return array Attendee data array.
     */
    private function create_attendee_data(int $userid, bool $ismoderator = false): array {
        return [
            'ext_user_id' => $userid,
            'moderator' => $ismoderator ? 'true' : 'false',
            'joins' => ['2026-01-01T10:05:00Z'],
            'leaves' => ['2026-01-01T10:55:00Z'],
            'duration' => 3000,
            'engagement' => [
                'chats' => 10,
                'talks' => 5,
                'raisehand' => 2,
                'emojis' => 3,
                'poll_votes' => 4,
                'talk_time' => 120,
            ],
        ];
    }

    /**
     * Test process_action does nothing when BNX is disabled.
     */
    public function test_process_action_skips_when_bnx_disabled(): void {
        global $DB;

        $this->disable_bnx_plugin();

        [$course, $bbb, $instance] = $this->create_bbb_with_bnx();

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $jsondata = $this->create_meeting_events_json([
            $this->create_attendee_data($user->id),
        ]);

        $addon = new broker_meeting_events_addons($instance, $jsondata);
        $addon->process_action();

        // Verify no meeting was logged.
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $bbb->id]);
        $meetings = $DB->get_records('bbbext_bnx_datahub_meeting', ['bnxid' => $bnx->id]);
        $this->assertEmpty($meetings);
    }

    /**
     * Test callbacks do not store attendees when the activity has no BNX record.
     */
    public function test_process_action_skips_without_bnx_record(): void {
        global $DB;

        $this->enable_bnx_plugin();
        [$course, $bbb, $instance] = $this->create_bbb_with_bnx();
        $DB->delete_records('bbbext_bnx', ['bigbluebuttonbnid' => $bbb->id]);

        $user = $this->getDataGenerator()->create_user();
        $jsondata = $this->create_meeting_events_json([$this->create_attendee_data($user->id)]);
        $addon = new broker_meeting_events_addons($instance, $jsondata);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $addon->process_action();
            $this->assertDebuggingCalled(
                '[bbbext_bnx_datahub] logger::log_meeting No BNX record found for instance.',
                DEBUG_DEVELOPER
            );
        }

        $this->assertSame(0, $DB->count_records('bbbext_bnx_datahub_meeting'));
        $this->assertSame(0, $DB->count_records('bbbext_bnx_datahub_meeting_user'));
    }

    /**
     * Test process_action logs meeting when BNX is enabled.
     */
    public function test_process_action_logs_meeting_when_bnx_enabled(): void {
        global $DB;

        $this->enable_bnx_plugin();

        [$course, $bbb, $instance] = $this->create_bbb_with_bnx();

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $jsondata = $this->create_meeting_events_json([
            $this->create_attendee_data($user->id),
        ]);

        $addon = new broker_meeting_events_addons($instance, $jsondata);
        $addon->process_action();

        // Verify meeting was logged.
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $bbb->id]);
        $meetings = $DB->get_records('bbbext_bnx_datahub_meeting', ['bnxid' => $bnx->id]);
        $this->assertCount(1, $meetings);

        $meeting = reset($meetings);
        $this->assertEquals('Events Test Meeting', $meeting->meetingname);
    }

    /**
     * Test process_action logs attendees when BNX is enabled.
     */
    public function test_process_action_logs_attendees(): void {
        global $DB;

        $this->enable_bnx_plugin();

        [$course, $bbb, $instance] = $this->create_bbb_with_bnx();

        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $jsondata = $this->create_meeting_events_json([
            $this->create_attendee_data($student->id, false),
            $this->create_attendee_data($teacher->id, true),
        ]);

        $addon = new broker_meeting_events_addons($instance, $jsondata);
        $addon->process_action();

        // Verify attendees were logged.
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $bbb->id]);
        $meeting = $DB->get_record('bbbext_bnx_datahub_meeting', ['bnxid' => $bnx->id]);
        $attendees = $DB->get_records('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meeting->id]);

        $this->assertCount(2, $attendees);
    }

    /**
     * Test process_action handles missing data gracefully.
     */
    public function test_process_action_handles_missing_data(): void {
        global $DB;

        $this->enable_bnx_plugin();

        [$course, $bbb, $instance] = $this->create_bbb_with_bnx();

        // JSON without data object.
        $jsondata = json_encode(['meeting_id' => 'test']);

        $addon = new broker_meeting_events_addons($instance, $jsondata);

        // Should not throw exception, but will trigger debugging.
        $addon->process_action();

        // Expect the debugging message about missing data.
        $this->assertDebuggingCalled('Warning: Missing data object in JSON.', DEBUG_DEVELOPER);

        // Verify no meeting was logged.
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $bbb->id]);
        $meetings = $DB->get_records('bbbext_bnx_datahub_meeting', ['bnxid' => $bnx->id]);
        $this->assertEmpty($meetings);
    }

    /**
     * Test process_action handles empty attendees gracefully.
     */
    public function test_process_action_handles_empty_attendees(): void {
        global $DB;

        $this->enable_bnx_plugin();

        [$course, $bbb, $instance] = $this->create_bbb_with_bnx();

        $jsondata = $this->create_meeting_events_json([]);

        $addon = new broker_meeting_events_addons($instance, $jsondata);
        $addon->process_action();

        // Expect the debugging message about no attendees.
        $this->assertDebuggingCalled();

        // Meeting should still be logged even with no attendees.
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $bbb->id]);
        $meetings = $DB->get_records('bbbext_bnx_datahub_meeting', ['bnxid' => $bnx->id]);
        $this->assertCount(1, $meetings);

        $meeting = reset($meetings);
        $attendees = $DB->get_records('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meeting->id]);
        $this->assertEmpty($attendees);
    }

    /**
     * Test process_action stores engagement data correctly.
     */
    public function test_process_action_stores_engagement_data(): void {
        global $DB;

        $this->enable_bnx_plugin();

        [$course, $bbb, $instance] = $this->create_bbb_with_bnx();

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $jsondata = $this->create_meeting_events_json([
            $this->create_attendee_data($user->id),
        ]);

        $addon = new broker_meeting_events_addons($instance, $jsondata);
        $addon->process_action();

        // Verify engagement data was stored.
        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $bbb->id]);
        $meeting = $DB->get_record('bbbext_bnx_datahub_meeting', ['bnxid' => $bnx->id]);
        $attendee = $DB->get_record('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meeting->id]);

        $this->assertEquals(10, $attendee->engchats);
        $this->assertEquals(5, $attendee->engtalks);
        $this->assertEquals(2, $attendee->engraisehand);
        $this->assertEquals(3, $attendee->engemojis);
        $this->assertEquals(4, $attendee->engpollvotes);
        $this->assertEquals(120, $attendee->engtalktime);
    }
}
