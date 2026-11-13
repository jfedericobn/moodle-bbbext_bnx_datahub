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

use bbbext_bnx_datahub\local\helpers\debug_helper;
use bbbext_bnx_datahub\logger;

/**
 * Broker meeting events addons for the bnx_datahub extension.
 *
 * When meeting_events callback is implemented by BigBlueButton, Moodle receives a POST request
 * which is processed in the function using super globals.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2025 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class broker_meeting_events_addons extends \mod_bigbluebuttonbn\local\extension\broker_meeting_events_addons {
    /**
     * Data processing action
     */
    public function process_action() {
        // Check if BNX is enabled.
        $enabledplugins = \core_plugin_manager::instance()->get_enabled_plugins('bbbext');
        if (!isset($enabledplugins['bnx'])) {
            return;
        }

        $jsonobj = json_decode($this->data);

        if (!is_object($jsonobj) || !isset($jsonobj->data) || !is_object($jsonobj->data)) {
            debug_helper::developer('Warning: Missing data object in JSON.');
            return;
        }

        // Deep clone to prevent log_meeting() from modifying the original object.
        $meetingdata = json_decode(json_encode($jsonobj));
        $meetingid = logger::log_meeting($this->instance, $meetingdata);

        if (!$meetingid) {
            return;
        }

        if (!empty($jsonobj->data->attendees) && is_array($jsonobj->data->attendees)) {
            // Batch all attendees in a single call so user lookups and existing-row
            // resolution are amortized to one DB round-trip per query (OL-3.1.2).
            logger::log_meeting_users($meetingid, $jsonobj->data->attendees);
        } else {
            debug_helper::developer('Warning: No attendees found or invalid type. Data: ' . json_encode($jsonobj->data));
        }
    }
}
