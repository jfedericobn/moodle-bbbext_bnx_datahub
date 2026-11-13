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

namespace bbbext_bnx_datahub\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use context_module;
use stdClass;

/**
 * Tests for the privacy provider class.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2025 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \bbbext_bnx_datahub\privacy\provider
 */
final class privacy_provider_test extends provider_testcase {
    /**
     * Set up for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Sidecar-contract bootstrap (CC-2).
        set_config('bigbluebuttonbn_meetingevents_enabled', 1);
    }

    /**
     * Create test data: course, activity, meeting, and attendee records.
     *
     * @param int $userid The user ID to create meeting data for.
     * @return array Array containing course, bbb, cm, and context.
     */
    private function create_test_data(int $userid): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $bbb = $this->getDataGenerator()->create_module('bigbluebuttonbn', [
            'course' => $course->id,
            'name' => 'Privacy Test Meeting',
        ]);
        $cm = get_coursemodule_from_instance('bigbluebuttonbn', $bbb->id);
        $context = context_module::instance($cm->id);

        $bnx = $DB->get_record('bbbext_bnx', ['bigbluebuttonbnid' => $bbb->id]);
        if (!$bnx) {
            $bnx = new stdClass();
            $bnx->bigbluebuttonbnid = $bbb->id;
            $bnx->id = $DB->insert_record('bbbext_bnx', $bnx);
        }

        // Create meeting record.
        $meeting = new stdClass();
        $meeting->bnxid = $bnx->id;
        $meeting->externalmeetingid = 'ext-privacy-test';
        $meeting->internalmeetingid = 'int-privacy-test';
        $meeting->meetingname = 'Privacy Test Meeting';
        $meeting->timestart = time() - 3600;
        $meeting->timeend = time();
        $meeting->duration = 3600;
        $meeting->metadata = json_encode(['meeting' => 'metadata', 'moderator' => 'Test User']);
        $meeting->rawdata = json_encode(['meeting' => 'rawdata', 'attendees' => ['Test User']]);
        $meeting->id = $DB->insert_record('bbbext_bnx_datahub_meeting', $meeting);

        // Create meeting user record.
        $meetinguser = new stdClass();
        $meetinguser->meetingid = $meeting->id;
        $meetinguser->userid = $userid;
        $meetinguser->fullname = 'Test User';
        $meetinguser->attendeerole = 'viewer';
        $meetinguser->timejoin = time() - 3500;
        $meetinguser->timeleave = time() - 100;
        $meetinguser->elapsedtime = 3400;
        $meetinguser->attendeescore = 85;
        $meetinguser->engchats = 10;
        $meetinguser->engtalks = 5;
        $meetinguser->engraisehand = 2;
        $meetinguser->engemojis = 3;
        $meetinguser->engpollvotes = 4;
        $meetinguser->engtalktime = 120;
        $meetinguser->rawdata = '{"test": "data"}';
        $DB->insert_record('bbbext_bnx_datahub_meeting_user', $meetinguser);

        return [$course, $bbb, $cm, $context];
    }

    /**
     * Test activity deletion removes only its analytics when DataHub is disabled.
     */
    public function test_activity_deletion_removes_analytics(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/filelib.php');
        set_config('sortorder', 1, 'bbbext_bnx');
        set_config('sortorder', 2, 'bbbext_bnx_datahub');
        set_config('coursebinenable', 0, 'tool_recyclebin');

        $user = $this->getDataGenerator()->create_user();
        [$course, $bbb, $cm] = $this->create_test_data($user->id);
        [$othercourse, $otherbbb] = $this->create_test_data($user->id);
        $meetingsql = "SELECT meeting.id
                         FROM {bbbext_bnx_datahub_meeting} meeting
                         JOIN {bbbext_bnx} bnx ON bnx.id = meeting.bnxid
                        WHERE bnx.bigbluebuttonbnid = :instanceid";
        $meetingid = $DB->get_field_sql($meetingsql, ['instanceid' => $bbb->id], MUST_EXIST);
        $othermeetingid = $DB->get_field_sql($meetingsql, ['instanceid' => $otherbbb->id], MUST_EXIST);
        set_config('disabled', 1, 'bbbext_bnx_datahub');

        \curl::mock_response('<response><returncode>SUCCESS</returncode></response>');
        (new \core_courseformat\local\cmactions($course))->delete($cm->id);

        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid]));
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meetingid]));
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $othermeetingid]));
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['meetingid' => $othermeetingid]));
    }

    /**
     * Test deferred activity deletion with the reverse extension order.
     */
    public function test_async_activity_deletion_removes_analytics(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/filelib.php');
        $this->setAdminUser();
        set_config('sortorder', 2, 'bbbext_bnx');
        set_config('sortorder', 1, 'bbbext_bnx_datahub');
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $user = $this->getDataGenerator()->create_user();
        [$course, $bbb, $cm] = $this->create_test_data($user->id);
        $meetingid = $DB->get_field_sql(
            "SELECT meeting.id
               FROM {bbbext_bnx_datahub_meeting} meeting
               JOIN {bbbext_bnx} bnx ON bnx.id = meeting.bnxid
              WHERE bnx.bigbluebuttonbnid = :instanceid",
            ['instanceid' => $bbb->id],
            MUST_EXIST
        );

        (new \core_courseformat\local\cmactions($course))->delete($cm->id, true);
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid]));
        \curl::mock_response('<response><returncode>SUCCESS</returncode></response>');
        ob_start();
        $this->run_all_adhoc_tasks();
        ob_end_clean();
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid]));
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meetingid]));
    }

    /**
     * Test course deletion removes its activity analytics.
     */
    public function test_course_deletion_removes_analytics(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/filelib.php');
        set_config('coursebinenable', 0, 'tool_recyclebin');
        set_config('categorybinenable', 0, 'tool_recyclebin');

        $user = $this->getDataGenerator()->create_user();
        [$course, $bbb] = $this->create_test_data($user->id);
        $meetingid = $DB->get_field_sql(
            "SELECT meeting.id
               FROM {bbbext_bnx_datahub_meeting} meeting
               JOIN {bbbext_bnx} bnx ON bnx.id = meeting.bnxid
              WHERE bnx.bigbluebuttonbnid = :instanceid",
            ['instanceid' => $bbb->id],
            MUST_EXIST
        );

        \curl::mock_response('<response><returncode>SUCCESS</returncode></response>');
        $this->assertTrue(delete_course($course, false, false));

        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid]));
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meetingid]));
    }

    /**
     * Test deferred course deletion while DataHub is disabled.
     */
    public function test_async_course_deletion_removes_analytics(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/filelib.php');
        $this->setAdminUser();
        set_config('coursebinenable', 0, 'tool_recyclebin');
        set_config('categorybinenable', 0, 'tool_recyclebin');
        set_config('enablecourseasyncdeletion', 1, 'moodlecourse');
        $user = $this->getDataGenerator()->create_user();
        [$course, $bbb] = $this->create_test_data($user->id);
        [$othercourse, $otherbbb] = $this->create_test_data($user->id);
        $meetingsql = "SELECT meeting.id
                         FROM {bbbext_bnx_datahub_meeting} meeting
                         JOIN {bbbext_bnx} bnx ON bnx.id = meeting.bnxid
                        WHERE bnx.bigbluebuttonbnid = :instanceid";
        $meetingid = $DB->get_field_sql($meetingsql, ['instanceid' => $bbb->id], MUST_EXIST);
        $othermeetingid = $DB->get_field_sql($meetingsql, ['instanceid' => $otherbbb->id], MUST_EXIST);
        set_config('disabled', 1, 'bbbext_bnx_datahub');

        $this->assertTrue(delete_course($course, false));
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid]));
        $this->assertNotFalse(get_config('bbbext_bnx_datahub', 'pending_course_' . $course->id));

        \curl::mock_response('<response><returncode>SUCCESS</returncode></response>');
        ob_start();
        $this->run_all_adhoc_tasks();
        ob_end_clean();

        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid]));
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meetingid]));
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $othermeetingid]));
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['meetingid' => $othermeetingid]));
        $this->assertFalse(get_config('bbbext_bnx_datahub', 'pending_course_' . $course->id));
    }

    /**
     * Test activity recycle-bin backup and restore retain analytics.
     */
    public function test_activity_recycle_bin_restores_analytics(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/filelib.php');
        $this->setAdminUser();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $user = $this->getDataGenerator()->create_user();
        [$course, $bbb, $cm] = $this->create_test_data($user->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $meetingid = $DB->get_field_sql(
            "SELECT meeting.id
               FROM {bbbext_bnx_datahub_meeting} meeting
               JOIN {bbbext_bnx} bnx ON bnx.id = meeting.bnxid
              WHERE bnx.bigbluebuttonbnid = :instanceid",
            ['instanceid' => $bbb->id],
            MUST_EXIST
        );

        \curl::mock_response('<response><returncode>SUCCESS</returncode></response>');
        (new \core_courseformat\local\cmactions($course))->delete($cm->id);
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid]));
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meetingid]));
        $bin = new \tool_recyclebin\course_bin($course->id);
        $items = $bin->get_items();
        $this->assertCount(1, $items);
        $bin->restore_item(reset($items));

        $restoredmeeting = $DB->get_record(
            'bbbext_bnx_datahub_meeting',
            ['internalmeetingid' => 'int-privacy-test'],
            '*',
            MUST_EXIST
        );
        $this->assertNotEquals($meetingid, $restoredmeeting->id);
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting_user', [
            'meetingid' => $restoredmeeting->id,
            'userid' => $user->id,
        ]));
    }

    /**
     * Test deferred course recycle-bin backup and restore retain analytics.
     */
    public function test_course_recycle_bin_restores_analytics(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/filelib.php');
        $this->setAdminUser();
        set_config('categorybinenable', 1, 'tool_recyclebin');
        set_config('enablecourseasyncdeletion', 1, 'moodlecourse');
        $user = $this->getDataGenerator()->create_user();
        [$course, $bbb] = $this->create_test_data($user->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $meetingid = $DB->get_field_sql(
            "SELECT meeting.id
               FROM {bbbext_bnx_datahub_meeting} meeting
               JOIN {bbbext_bnx} bnx ON bnx.id = meeting.bnxid
              WHERE bnx.bigbluebuttonbnid = :instanceid",
            ['instanceid' => $bbb->id],
            MUST_EXIST
        );

        $this->assertTrue(delete_course($course, false));
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid]));
        \curl::mock_response('<response><returncode>SUCCESS</returncode></response>');
        ob_start();
        $this->run_all_adhoc_tasks();
        ob_end_clean();
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting', ['id' => $meetingid]));
        $this->assertFalse($DB->record_exists('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meetingid]));
        $bin = new \tool_recyclebin\category_bin($course->category);
        $items = $bin->get_items();
        $this->assertCount(1, $items);
        $bin->restore_item(reset($items));

        $restoredmeeting = $DB->get_record(
            'bbbext_bnx_datahub_meeting',
            ['internalmeetingid' => 'int-privacy-test'],
            '*',
            MUST_EXIST
        );
        $this->assertNotEquals($meetingid, $restoredmeeting->id);
        $this->assertTrue($DB->record_exists('bbbext_bnx_datahub_meeting_user', [
            'meetingid' => $restoredmeeting->id,
            'userid' => $user->id,
        ]));
    }

    /**
     * Test get_metadata returns the expected metadata.
     */
    public function test_get_metadata(): void {
        $collection = new collection('bbbext_bnx_datahub');
        $collection = provider::get_metadata($collection);

        $items = $collection->get_collection();
        $this->assertCount(2, $items);

        // Check that both tables are declared.
        $tables = [];
        foreach ($items as $item) {
            $tables[$item->get_name()] = $item;
        }
        $this->assertArrayHasKey('bbbext_bnx_datahub_meeting', $tables);
        $this->assertArrayHasKey('bbbext_bnx_datahub_meeting_user', $tables);

        $table = $tables['bbbext_bnx_datahub_meeting_user'];

        $privacyfields = $table->get_privacy_fields();
        $this->assertArrayHasKey('userid', $privacyfields);
        $this->assertArrayHasKey('fullname', $privacyfields);
        $this->assertArrayHasKey('attendeerole', $privacyfields);
        $this->assertArrayHasKey('timejoin', $privacyfields);
        $this->assertArrayHasKey('timeleave', $privacyfields);
        $this->assertArrayHasKey('elapsedtime', $privacyfields);
        $this->assertArrayHasKey('attendeescore', $privacyfields);
        $this->assertArrayHasKey('engchats', $privacyfields);
        $this->assertArrayHasKey('engtalks', $privacyfields);
        $this->assertArrayHasKey('engraisehand', $privacyfields);
        $this->assertArrayHasKey('engemojis', $privacyfields);
        $this->assertArrayHasKey('engpollvotes', $privacyfields);
        $this->assertArrayHasKey('engtalktime', $privacyfields);
        $this->assertArrayHasKey('rawdata', $privacyfields);

        $meetingtable = $tables['bbbext_bnx_datahub_meeting'];
        $meetingfields = $meetingtable->get_privacy_fields();
        $this->assertArrayHasKey('metadata', $meetingfields);
        $this->assertArrayHasKey('rawdata', $meetingfields);
    }

    /**
     * Test get_contexts_for_userid returns the correct contexts.
     */
    public function test_get_contexts_for_userid(): void {
        $user = $this->getDataGenerator()->create_user();
        [$course, $bbb, $cm, $context] = $this->create_test_data($user->id);

        $contextlist = provider::get_contexts_for_userid($user->id);

        $this->assertCount(1, $contextlist);
        $this->assertEquals($context->id, $contextlist->get_contextids()[0]);
    }

    /**
     * Test get_contexts_for_userid returns empty for user without data.
     */
    public function test_get_contexts_for_userid_no_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        // Create data for other user.
        $this->create_test_data($otheruser->id);

        // Query for user without data.
        $contextlist = provider::get_contexts_for_userid($user->id);

        $this->assertCount(0, $contextlist);
    }

    /**
     * Test get_users_in_context returns the correct users.
     */
    public function test_get_users_in_context(): void {
        $user = $this->getDataGenerator()->create_user();
        [$course, $bbb, $cm, $context] = $this->create_test_data($user->id);

        $userlist = new userlist($context, 'bbbext_bnx_datahub');
        provider::get_users_in_context($userlist);

        $this->assertCount(1, $userlist);
        $this->assertEquals($user->id, $userlist->get_userids()[0]);
    }

    /**
     * Test export_user_data exports the correct data.
     */
    public function test_export_user_data(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        [$course, $bbb, $cm, $context] = $this->create_test_data($user->id);
        $DB->set_field('bbbext_bnx_datahub_meeting', 'excluded', 1, ['meetingname' => 'Privacy Test Meeting']);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $approvedcontextlist = new approved_contextlist($user, 'bbbext_bnx_datahub', $contextlist->get_contextids());

        provider::export_user_data($approvedcontextlist);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([
            get_string('pluginname', 'bbbext_bnx_datahub'),
            'Privacy Test Meeting',
        ]);

        $this->assertSame('Privacy Test Meeting', $data->meetingname);
        $this->assertSame(json_encode(['meeting' => 'metadata', 'moderator' => 'Test User']), $data->meeting_metadata);
        $this->assertSame(json_encode(['meeting' => 'rawdata', 'attendees' => ['Test User']]), $data->meeting_rawdata);
    }

    /**
     * Test delete_data_for_all_users_in_context deletes all user data.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        [$course, $bbb, $cm, $context] = $this->create_test_data($user1->id);

        $DB->set_field('bbbext_bnx_datahub_meeting', 'excluded', 1, ['meetingname' => 'Privacy Test Meeting']);

        // Add another user's data to the same meeting.
        $meeting = $DB->get_record('bbbext_bnx_datahub_meeting', ['meetingname' => 'Privacy Test Meeting']);
        $meetinguser = new stdClass();
        $meetinguser->meetingid = $meeting->id;
        $meetinguser->userid = $user2->id;
        $meetinguser->fullname = 'Test User 2';
        $meetinguser->attendeerole = 'viewer';
        $meetinguser->timejoin = time() - 3500;
        $meetinguser->timeleave = time() - 100;
        $meetinguser->elapsedtime = 3400;
        $meetinguser->attendeescore = 0;
        $meetinguser->engchats = 0;
        $meetinguser->engtalks = 0;
        $meetinguser->engraisehand = 0;
        $meetinguser->engemojis = 0;
        $meetinguser->engpollvotes = 0;
        $meetinguser->engtalktime = 0;
        $meetinguser->rawdata = '{}';
        $DB->insert_record('bbbext_bnx_datahub_meeting_user', $meetinguser);

        // Verify both users have records.
        $this->assertEquals(2, $DB->count_records('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meeting->id]));

        // Delete all data for the context.
        provider::delete_data_for_all_users_in_context($context);

        // Verify all records are deleted.
        $this->assertEquals(0, $DB->count_records('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meeting->id]));
        $this->assertEquals(0, $DB->count_records('bbbext_bnx_datahub_meeting', ['id' => $meeting->id]));
    }

    /**
     * Test delete_data_for_user deletes only the specified user's data.
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        [$course, $bbb, $cm, $context] = $this->create_test_data($user1->id);

        // Add another user's data to the same meeting.
        $meeting = $DB->get_record('bbbext_bnx_datahub_meeting', ['meetingname' => 'Privacy Test Meeting']);
        $meetinguser = new stdClass();
        $meetinguser->meetingid = $meeting->id;
        $meetinguser->userid = $user2->id;
        $meetinguser->fullname = 'Test User 2';
        $meetinguser->attendeerole = 'viewer';
        $meetinguser->timejoin = time() - 3500;
        $meetinguser->timeleave = time() - 100;
        $meetinguser->elapsedtime = 3400;
        $meetinguser->attendeescore = 0;
        $meetinguser->engchats = 0;
        $meetinguser->engtalks = 0;
        $meetinguser->engraisehand = 0;
        $meetinguser->engemojis = 0;
        $meetinguser->engpollvotes = 0;
        $meetinguser->engtalktime = 0;
        $meetinguser->rawdata = '{}';
        $DB->insert_record('bbbext_bnx_datahub_meeting_user', $meetinguser);

        // Verify both users have records.
        $this->assertEquals(2, $DB->count_records('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meeting->id]));

        // Delete data for user1 only.
        $contextlist = provider::get_contexts_for_userid($user1->id);
        $approvedcontextlist = new approved_contextlist($user1, 'bbbext_bnx_datahub', $contextlist->get_contextids());
        provider::delete_data_for_user($approvedcontextlist);

        // Verify user1's record is deleted but user2's remains.
        $this->assertEquals(0, $DB->count_records('bbbext_bnx_datahub_meeting_user', [
            'meetingid' => $meeting->id,
            'userid' => $user1->id,
        ]));
        $this->assertEquals(1, $DB->count_records('bbbext_bnx_datahub_meeting_user', [
            'meetingid' => $meeting->id,
            'userid' => $user2->id,
        ]));
        $this->assertEquals(1, $DB->count_records('bbbext_bnx_datahub_meeting', ['id' => $meeting->id]));
    }

    /**
     * Test delete_data_for_users deletes data for specified users.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        [$course, $bbb, $cm, $context] = $this->create_test_data($user1->id);

        // Add more users' data to the same meeting.
        $meeting = $DB->get_record('bbbext_bnx_datahub_meeting', ['meetingname' => 'Privacy Test Meeting']);

        foreach ([$user2, $user3] as $user) {
            $meetinguser = new stdClass();
            $meetinguser->meetingid = $meeting->id;
            $meetinguser->userid = $user->id;
            $meetinguser->fullname = fullname($user);
            $meetinguser->attendeerole = 'viewer';
            $meetinguser->timejoin = time() - 3500;
            $meetinguser->timeleave = time() - 100;
            $meetinguser->elapsedtime = 3400;
            $meetinguser->attendeescore = 0;
            $meetinguser->engchats = 0;
            $meetinguser->engtalks = 0;
            $meetinguser->engraisehand = 0;
            $meetinguser->engemojis = 0;
            $meetinguser->engpollvotes = 0;
            $meetinguser->engtalktime = 0;
            $meetinguser->rawdata = '{}';
            $DB->insert_record('bbbext_bnx_datahub_meeting_user', $meetinguser);
        }

        // Verify all three users have records.
        $this->assertEquals(3, $DB->count_records('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meeting->id]));

        // Delete data for user1 and user2.
        $approveduserlist = new approved_userlist($context, 'bbbext_bnx_datahub', [$user1->id, $user2->id]);
        provider::delete_data_for_users($approveduserlist);

        // Verify only user3's record remains.
        $this->assertEquals(1, $DB->count_records('bbbext_bnx_datahub_meeting_user', ['meetingid' => $meeting->id]));
        $this->assertEquals(1, $DB->count_records('bbbext_bnx_datahub_meeting_user', [
            'meetingid' => $meeting->id,
            'userid' => $user3->id,
        ]));
        $this->assertEquals(1, $DB->count_records('bbbext_bnx_datahub_meeting', ['id' => $meeting->id]));
    }
}
