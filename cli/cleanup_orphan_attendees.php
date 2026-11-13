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
 * Preview or delete orphan DataHub attendees or meetings.
 *
 * @package   bbbext_bnx_datahub
 * @copyright 2026 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use bbbext_bnx_datahub\local\services\orphan_cleanup_service;

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'apply' => false,
        'orphan-meetings' => false,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($unrecognized)) {
    $unrecognized = implode(' ', $unrecognized);
    cli_error("Unrecognised option(s): {$unrecognized}");
}

if (!is_bool($options['apply']) || !is_bool($options['orphan-meetings'])) {
    cli_error('The --apply and --orphan-meetings options do not accept values.');
}

if (!empty($options['help'])) {
    $help = <<<TXT
Preview or delete orphan DataHub records. By default, select attendees whose meeting no longer exists.

The default is a read-only preview. Deletion is irreversible; back up the site first.
Run this from the Moodle root as: php public/mod/bigbluebuttonbn/extension/bnx_datahub/cli/cleanup_orphan_attendees.php

Options:
    --orphan-meetings  Select meetings whose BNX record no longer exists, and their attendees
    --apply            Delete the selected orphan records
    -h, --help         Print this help
TXT;
    cli_writeln($help);
    exit(0);
}

$apply = $options['apply'];
if ($options['orphan-meetings']) {
    cli_heading('BNX DataHub orphan meeting cleanup');
    $counts = orphan_cleanup_service::clean_orphan_meetings($apply);
    cli_writeln("Orphan meetings found: {$counts['meetings']}");
    cli_writeln("Attendee records in those meetings: {$counts['attendees']}");
} else {
    cli_heading('BNX DataHub orphan attendee cleanup');
    $count = orphan_cleanup_service::clean($apply);
    cli_writeln("Orphan attendee records found: {$count}");
}
if ($apply) {
    cli_writeln('Cleanup completed.');
} else {
    cli_writeln('Preview only; no records were deleted. Use --apply to delete these records.');
}
