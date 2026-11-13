# BNX Data Hub Developer Guide

## Role in the BNX family

`bbbext_bnx_datahub` is a passive `bbbext` analytics sidecar. It depends on the
BNX base record for activity linkage and consumes BigBlueButton broker meeting
events supplied by the parent `mod_bigbluebuttonbn` module. It must never alter
parent settings, BNX settings, or meeting behavior.

DataHub `1.2-beta.1` targets Moodle 5.3 and the BNX 1.3 contract (minimum BNX
version code `2026110500`). Compatibility with other Moodle or BNX release
lines requires separate validation of parent hook signatures and Report Builder
APIs.

## Extension hook and event flow

The implementation class
`bbbext_bnx_datahub\bigbluebuttonbn\broker_meeting_events_addons` receives the
parent broker's event payload. It returns early when BNX is unavailable, then
resolves the activity and delegates persistence to `logger`.

```text
BigBlueButton callback -> parent broker -> DataHub broker addon
  -> logger -> meeting and attendee tables -> Report Builder reports
```

Do not add a parallel HTTP endpoint for callback ingestion. The parent broker
is responsible for authenticating and dispatching BigBlueButton events.
DataHub checks the meeting ID against the parent activity using the parent's
group-aware prefix rule and rejects an internal meeting ID already linked to a
different activity. The parent does not pass its callback result to the addon,
so replays still reach DataHub and can update existing records. This permits
retries after partial ingestion but does not prevent changed replay payloads.

## Persistence services

`logger` is the ingestion facade. Its services isolate table access:

| Service | Responsibility |
| --- | --- |
| `meeting_service` | Upserts a meeting session tied to a BNX activity. |
| `attendance_service` | Upserts attendees and engagement counters for a session. |

Meeting rows live in `bbbext_bnx_datahub_meeting`; attendee rows live in
`bbbext_bnx_datahub_meeting_user`. Preserve the activity-to-BNX-to-meeting
foreign-key chain. Event payloads may be incomplete or repeated, so logger
code must be defensive and idempotent.

Callback meeting identifiers must be nonempty strings of at most 255 characters;
invalid identifiers are rejected rather than truncated. Missing meeting names
fall back to the activity name, and names are limited to the database field size.
Malformed attendee records are skipped without blocking other attendees. For
duplicate Moodle user IDs in one callback, the first valid entry is retained and
subsequent entries are skipped; their metrics are not merged. Developer debug
logging reports skipped entries when enabled. Unexpected database errors still
propagate, and earlier writes may already have succeeded. Duplicate semantics
have not been confirmed against captured BigBlueButton callbacks.

## Report Builder integration

DataHub's entities define reusable columns and filters. Datasources combine
those entities for custom reports; the attendance and engagement endpoints use
the system report factory for fixed activity-facing reports. Add a new metric by
updating the schema/upgrade path, ingestion mapping, entity column, datasource,
privacy export, and appropriate tests as one feature.

The activity navigation exposes **Dashboard +** only when prerequisites are
met and the viewer has `bbbext/bnx_datahub:viewreports`. The dashboard shows
cross-session charts and a selectable post-session attendee overview using the
metrics already persisted by DataHub. Session details refresh asynchronously
when a different session is selected. Attendance and engagement Report Builder
pages remain available as detailed views from the dashboard.

The optional `meetingid` dashboard parameter is accepted only when the meeting
belongs to the current BigBlueButton activity; invalid IDs fall back to its most
recent meeting. Navigation is a convenience, not an authorization boundary;
all dashboard and report endpoints must enforce access themselves.

## Sidecar contract and status checks

DataHub observes `\bbbext_bnx\event\state_changed` and writes only its own
disabled configuration. `lib.php` registers status checks for BNX availability,
the parent meeting-events setting, and legacy BN Reports conflicts. Add a check
when DataHub has a new external prerequisite; do not silently repair it.

## Testing

Run focused Moodle PHPUnit tests with Moodle bootstrap:

```bash
php vendor/bin/phpunit -c phpunit.xml \
  public/mod/bigbluebuttonbn/extension/bnx_datahub/tests/logger_test.php
```

The key suites cover broker ingestion, services, Report Builder entities and
reports, navigation, migrations, prerequisite checks, and the Privacy API.
Behat scenarios in `tests/behat/` cover report visibility, permissions,
navigation, and stored data behavior.

When changing event parsing, add duplicate/out-of-order/missing-field coverage.
When changing reports, test capability denial as well as expected columns and
filters.
