# Moodle Ranking Block (Fork)

> **Fork of [hyukudan/moodle-block_ranking](https://github.com/hyukudan/moodle-block_ranking)**
> The original plugin has been abandoned and is incompatible with modern Moodle versions.
> This fork brings it up to date with **Moodle 4.5+ / 5.x**, fixing critical security issues, adding GDPR compliance, modernizing the UI, and adding new features.

## What is this plugin?

This block adds gamification to Moodle through a **student ranking system**. Students earn points by completing course activities, and a leaderboard displays their positions. The plugin listens to Moodle events in real-time -- no cron dependency required.

The ranking works with **activity completion tracking**: you need to enable it and configure completion criteria for the activities you want to monitor.

### How points work

- Students earn configurable points for completing activities (default: 2 points each).
- Activities with grades (assignments, quizzes, forums, etc.) award **base points + (grade x multiplier)**. For example: completing an assignment with a grade of 10 and multiplier 1.0 gives 2 + 10 = 12 points.
- Points are configurable per activity type (assign, resource, forum, page, workshop, quiz, lesson, SCORM, URL) in the plugin settings.
- A **grade multiplier** setting allows scaling grade-based bonus points (e.g., 0.5 for half grade, 2.0 for double).

## What's new in this fork

### Security fixes
- **Fixed SQL injection vulnerability** in `lib.php` -- hardcoded `mdl_` table prefix replaced with Moodle's `{tablename}` syntax
- **Removed hardcoded role ID 5** -- the plugin assumed the student role was always ID 5, which is only true on default installations. Now uses a **configurable multi-select setting** with `get_archetype_roles('student')` as the default
- **Replaced deprecated `user_has_role_assignment()`** -- uses direct `{role_assignments}` queries compatible with Moodle 5.x
- **Removed deprecated `classpath`** parameter from web service definition

### GDPR / Privacy API compliance
- **Full Privacy API implementation** (`classes/privacy/provider.php`) -- the plugin now properly declares what personal data it stores, and supports data export and deletion on GDPR requests. This is **mandatory** for Moodle 4.x+ plugins.

### Performance improvements
- **Database indexes** added to `ranking_points` and `ranking_logs` tables:
  - `ranking_points(courseid, userid)` -- UNIQUE, main ranking query optimization
  - `ranking_points(userid)` -- user lookups
  - `ranking_logs(rankingid)` -- JOIN optimization
  - `ranking_logs(rankingid, timecreated)` -- composite index for date-filtered ranking queries
  - `ranking_logs(course_modules_completion)` -- duplicate detection
  - `ranking_logs(courseid)` -- report page chart queries
- **Moodle Cache API (MUC)** integration -- ranking queries are cached for 5 minutes with **targeted per-course invalidation** when points change. Reduces database load significantly on courses with many students.
- **Atomic point updates** -- uses `UPDATE SET points = points + :newpoints` to avoid race conditions under concurrent completions
- **Transactional writes** -- point additions and log entries are wrapped in a database transaction to prevent orphaned records on partial failures.
- **Cross-database SQL** -- all queries use portable SQL (no MySQL-specific functions like `FROM_UNIXTIME`)

### Modernized UI/UX
- **Visual podium** -- top 3 positions displayed with gold/silver/bronze colored badges
- **Circular avatars** next to student names
- **Progress bars** showing relative points visually
- **Current user highlight** -- your row is highlighted in blue
- **Responsive design** -- mobile-friendly layout
- **Subtle entry animations** for ranking items
- **Bootstrap 5** compatible tabs and components
- **Your Score card** -- clean grid layout showing general/weekly/monthly points

### Enhanced report page
- **Period filter** -- filter ranking by All time, Weekly, or Monthly
- **Real pagination** -- navigate through pages of ranked students with Previous/Next buttons
- **CSV export** -- download full ranking data as CSV file (exports all records, not just current page)
- **Points evolution chart** -- line chart showing daily points over time (using Moodle's Chart API)
- **Group selector** -- filter by course groups

### Configurable points system
- **Extended activity types** -- individual point settings for quiz, lesson, SCORM, and URL activities (in addition to the existing assign, resource, forum, page, workshop)
- **Grade multiplier** -- configurable multiplier for grade-based bonus points
- **Streamlined config** -- map-based activity point lookup instead of switch statement

### Notifications
- **Moodle Message API integration** -- notifications for ranking changes
  - **Top 3 alert** -- notified when entering the top 3 (deduplicated -- only triggers on actual position change)
  - **Overtaken alert** -- notified when another student passes you in the ranking
  - **Weekly summary** -- scheduled task sends position summaries every Monday at 8:00
- Configurable via user notification preferences (popup, email, mobile push)

### New web service endpoints
- `block_ranking_get_user_position` -- get current user's ranking position and points
- `block_ranking_get_user_points_history` -- get user's point transaction history
- All endpoints registered with Moodle Mobile service

### Custom events for plugin integration
- **`\block_ranking\event\points_awarded`** -- fired when points are awarded, allowing other plugins (e.g., local_achievements) to observe and react
- Event includes: points awarded, total points, course context

### Auto-refresh
- **AMD JavaScript module** (`amd/src/ranking.js`) -- auto-refreshes ranking data every 60 seconds via AJAX polling
- Pauses when browser tab is hidden (saves resources)
- Smooth transition animations on point updates

### Testing
- **PHPUnit tests** -- real unit tests replacing the placeholder (points addition, duplicate detection, ranking order, role filtering, privacy export/delete, cache invalidation)
- **Behat tests** -- acceptance tests for block addition, student score display, report page features

### Robustness
- **Comprehensive null safety** -- all `$DB->get_record()` returns checked before property access
- **Observer error handling** -- event observer wrapped in try-catch with `debugging()` logging
- **CSRF protection** -- CSV export requires `sesskey` validation
- **XSS prevention** -- Mustache templates use double-stache `{{var}}` for text output; form inputs validated with `PARAM_TEXT`/`PARAM_FLOAT`
- **Timezone-aware dates** -- weekly/monthly rankings use `usergetdate()` and site `calendar_startwday` setting
- **Privacy API transactions** -- all GDPR delete operations wrapped in database transactions

### Bug fixes
- Fixed `$stirng` typos in both English and Portuguese language files
- Fixed scale grade text-to-number coercion for non-numeric scale values
- Fixed division by zero in group points average calculation

## Requirements

- **Moodle 4.5+** (tested up to Moodle 5.1.1)
- Activity completion tracking enabled at site and course level

## Installation

### Option 1: Git clone
```bash
cd /path/to/moodle/blocks
git clone https://github.com/hyukudan/moodle-block_ranking.git ranking
```
Then visit **Site administration > Notifications** to complete the installation.

### Option 2: Manual download
1. Download and extract this repository
2. Place the folder as `blocks/ranking` in your Moodle installation
3. Visit **Site administration > Notifications** to complete the installation

### Upgrading from the original plugin
If you have the original `block_ranking` installed, this fork is a **drop-in replacement**. The upgrade process will:
- Add database indexes (non-destructive)
- Existing data is fully preserved
- New cache definitions and message providers registered automatically

## Configuration

After installation:

1. **Add the block** to any course page
2. **Configure settings** at *Site administration > Plugins > Blocks > Ranking block*:
   - **Student roles**: Select which roles count as "students" for the ranking
   - **Points per activity type**: Configure points for resources, assignments, forums, pages, workshops, quizzes, lessons, SCORM, URLs, and a default for other types
   - **Grade multiplier**: Scale grade-based bonus points (default: 1.0)
   - **Ranking size**: Number of students shown in the block
   - **Multiple quiz attempts**: Whether repeated quiz attempts earn additional points

### Enabling completion tracking
1. Go to *Site administration > Advanced features*
2. Enable **Completion tracking**
3. Inside each course: *Course settings > Completion tracking* = Yes
4. Configure completion criteria for individual activities

## Features

- **General ranking** -- all-time leaderboard
- **Weekly ranking** -- resets each week for ongoing motivation
- **Monthly ranking** -- monthly leaderboard
- **Group support** -- filter rankings by course groups
- **Full report** -- paginated student ranking with period filter, CSV export, and evolution chart
- **Group graphs** -- visual charts for group points, averages, and weekly evolution
- **Web service API** -- 3 endpoints registered with Moodle Mobile service
- **Configurable student roles** -- no longer hardcoded to role ID 5
- **Notifications** -- Moodle messaging for top 3 entry, overtaken alerts, and weekly summaries
- **Custom events** -- `points_awarded` event for plugin integration
- **Auto-refresh** -- AJAX-based live ranking updates
- **GDPR compliant** -- full Privacy API implementation
- **Cached** -- MUC integration for high-traffic courses

## For developers

### Integration with other plugins

Other plugins can observe the `\block_ranking\event\points_awarded` event:

```php
// In your plugin's db/events.php:
$observers = [
    [
        'eventname' => '\block_ranking\event\points_awarded',
        'callback' => '\local_yourplugin\observer::ranking_points_awarded',
    ],
];
```

The event's `other` array contains `points` (awarded this time) and `totalpoints` (accumulated total).

### Building AMD modules

From the Moodle root directory:
```bash
npx grunt amd --root=blocks/ranking
```

## License

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html).

Original work copyright 2017 Willian Mano (http://conecti.me).
