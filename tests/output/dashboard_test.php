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

namespace bbbext_bnx_datahub\output;

use advanced_testcase;

/**
 * Tests for the dashboard output component.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2026 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \bbbext_bnx_datahub\output\dashboard
 */
final class dashboard_test extends advanced_testcase {
    /**
     * Empty dashboard data renders its empty-state context and detail links.
     */
    public function test_export_empty_dashboard(): void {
        global $OUTPUT;

        $dashboard = new dashboard((object) [
            'hasdata' => false,
        ], 42);
        $data = $dashboard->export_for_template($OUTPUT);

        $this->assertFalse($data->hasdata);
        $this->assertObjectHasProperty('emptytitle', $data);
        $this->assertStringContainsString('attendance.php?cmid=42', $data->attendanceurl);
        $this->assertStringContainsString('engagement.php?cmid=42', $data->engagementurl);
        $this->assertStringContainsString('excluded.php?cmid=42', $data->excludedurl);

        $excluded = (new dashboard((object) ['hasdata' => false, 'excludedcount' => 2], 42))->export_for_template($OUTPUT);
        $html = $OUTPUT->render_from_template('bbbext_bnx_datahub/dashboard', $excluded);
        $this->assertStringContainsString('Excluded sessions (2)', $html);
        $this->assertStringContainsString('All sessions are excluded', $html);
    }

    /**
     * Populated dashboard data renders all visual summary sections.
     */
    public function test_export_populated_dashboard(): void {
        global $OUTPUT;

        $input = (object) [
            'hasdata' => true,
            'meetingcount' => 2,
            'uniquelearnercount' => 3,
            'medianattendance' => 1800,
            'pollparticipantcount' => 2,
            'metrics' => (object) [
                'chats' => 8,
                'talks' => 5,
                'raisedhands' => 3,
                'emojis' => 1,
                'pollvotes' => 4,
                'talktime' => 120,
            ],
            'sessions' => [
                (object) ['id' => 1, 'label' => '1 Jan 2026', 'learnercount' => 2],
                (object) ['id' => 2, 'label' => '2 Jan 2026', 'learnercount' => 3],
            ],
            'selectedmeeting' => (object) [
                'id' => 2,
                'meetingname' => 'Session two',
                'timestart' => 2000,
                'duration' => 3600,
            ],
            'selectedmetrics' => (object) [
                'learnercount' => 1,
                'medianattendance' => 1800,
                'pollparticipantcount' => 1,
                'chats' => 4,
                'talks' => 2,
                'talktime' => 120,
                'raisedhands' => 1,
                'emojis' => 3,
                'pollvotes' => 2,
            ],
            'selectedlearners' => [
                (object) [
                    'fullname' => 'Ada Lovelace',
                    'elapsedtime' => 1800,
                    'score' => 42,
                    'chats' => 4,
                    'talks' => 2,
                    'talktime' => 120,
                    'raisedhands' => 1,
                    'emojis' => 3,
                    'pollvotes' => 2,
                ],
            ],
        ];
        $dashboard = new dashboard($input, 42);
        $data = $dashboard->export_for_template($OUTPUT);

        $this->assertTrue($data->hasdata);
        $this->assertCount(4, $data->metrics);
        $this->assertNotEmpty($data->attendancechart);
        $this->assertNotEmpty($data->engagementchart);
        $this->assertNotEmpty($data->learnerchart);
        $this->assertCount(5, $data->session->sessionmetrics);
        $this->assertSame('1 ' . get_string('hour'), $data->session->selectedsession->duration);
        $this->assertCount(1, $data->session->attendees);
        $this->assertSame('Ada Lovelace', $data->session->attendees[0]->fullname);
        $this->assertTrue($data->session->sessions[0]->selected);
        $this->assertStringContainsString('session.php?cmid=42', $data->session->sessionfragmenturl);
        $html = $OUTPUT->render_from_template('bbbext_bnx_datahub/dashboard', $data);
        $this->assertStringContainsString($data->session->selectedsession->duration, $html);
        $this->assertStringContainsString('chart-area', $html);
        $this->assertLessThan(
            strpos($html, 'chart-area', strpos($html, 'bbbext-bnx-datahub-session-title')),
            strpos($html, 'name="meetingid"')
        );
        $this->assertStringNotContainsString('name="action" value="exclude"', $html);

        $managed = (new dashboard($input, 42, true))->export_for_template($OUTPUT);
        $managedhtml = $OUTPUT->render_from_template('bbbext_bnx_datahub/dashboard', $managed);
        $this->assertStringContainsString('name="action" value="exclude"', $managedhtml);
        $this->assertNotEmpty((new dashboard($input, 42))->export_session_for_template()->learnerchart);

        $input->selectedlearners = [];
        $emptydata = (new dashboard($input, 42))->export_for_template($OUTPUT);
        $this->assertSame('', $emptydata->learnerchart);
        $this->assertFalse($emptydata->session->hasattendees);
        $emptyhtml = $OUTPUT->render_from_template('bbbext_bnx_datahub/dashboard', $emptydata);
        $this->assertStringContainsString($emptydata->session->noattendees, $emptyhtml);
        $this->assertSame(1, substr_count($emptyhtml, $emptydata->session->noattendees));
    }
}
