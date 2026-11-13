# BNX Data Hub Data, Privacy, and Security

## Stored analytics data

DataHub records BigBlueButton meeting callbacks in two tables:

| Table | Data |
| --- | --- |
| `bbbext_bnx_datahub_meeting` | Activity linkage, BigBlueButton meeting IDs, name, start/end time, duration, exclusion status, metadata, and raw event payload. |
| `bbbext_bnx_datahub_meeting_user` | User linkage where known, display name, role, join/leave time, duration, engagement counters, score, and raw event payload. |

The attendee table may represent a guest without a Moodle user ID. It is still
activity-context analytics data and must be treated as potentially personal
information in operational exports and database access.

## Access control

Report access requires `bbbext/bnx_datahub:viewreports` in course context.
The navigation addon hides links from unauthorized users, but report endpoints
must remain capability-protected. Do not expose meeting or attendee rows through
an unauthenticated endpoint or grant this capability to learner roles by
default.

Changing session exclusion requires `bbbext/bnx_datahub:manageexclusions` at
the activity context. Changes are logged as Moodle events. Exclusion only changes
default reporting visibility; it does not delete data or remove it from privacy
exports and deletion requests. Backups that include analytics preserve the
status, and older backups without it restore sessions as included.

## Backup and restore

Meeting records, names, identifiers, metrics, and raw payloads can contain
personal data. DataHub includes its analytics only when the activity's user
data is included and the backup is not anonymized. Backups without users,
anonymized backups, and backups excluding user data for the activity omit all
DataHub meetings and attendees, including exclusion status.

Restores also honor the activity's user-data setting and do not restore
analytics from anonymized archives, including older archives that contain
them. When an attendee cannot be mapped to a Moodle user, including a guest
without a user account, that attendee is not restored. The meeting's metadata
and raw payload and all attendee raw payloads for that meeting are also
discarded because they may identify the missing participant. Fully mapped,
non-anonymized restores retain those payloads. These rules also apply to
recycle-bin restores.

## Moodle Privacy API

The privacy provider declares its tables and implements context discovery,
user-list discovery, export, and deletion for user-associated attendance and
engagement records. Any schema change that adds user-linked data must update:

1. `classes/privacy/provider.php` metadata and export/delete behavior;
2. language strings describing the new data; and
3. `tests/privacy_provider_test.php`.

## Retention and deletion

DataHub does not impose a separate retention scheduler. Site administrators
should apply their Moodle retention, backup, and privacy-erasure policies to
the courses containing BigBlueButton activities. When an activity or its
course is deleted, DataHub removes its meeting and attendee records after
Moodle has taken any recycle-bin backup. Deferred deletions retain analytics
until the background task completes. A recycle-bin restore can restore the
analytics included in that backup; existing backups remain subject to the
site's backup retention policy.

Deletion failures should be investigated in Moodle logs. Historical records
whose BNX record is missing can be reviewed using the separate orphan-meeting
cleanup mode described in the administrator guide.

When deploying these callbacks without changing the installed plugin version,
purge Moodle caches so cached callback and event-observer lists are refreshed.

Raw callback data can contain more information than a rendered report. Limit
direct database access, protect backups, and avoid copying raw payloads into
logs or support requests unless they have been reviewed and sanitized.

## Migration safety

The optional BN Reports migration is intended for one-time legacy data transfer.
Run it in a maintenance window, back up the site first, validate record counts,
and leave the old reporting sidecar disabled after success. The DataHub sidecar
does not change parent or BNX configuration as part of this process.
