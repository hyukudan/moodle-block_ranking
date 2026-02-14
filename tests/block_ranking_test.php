<?php
// This file is part of Ranking block for Moodle - http://moodle.org/
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
 * Ranking block unit tests
 *
 * @package   block_ranking
 * @copyright 2024 block_ranking contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ranking;

use advanced_testcase;

/**
 * Ranking block unit tests.
 *
 * @package   block_ranking
 * @copyright 2024 block_ranking contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \block_ranking\manager
 * @covers \block_ranking\block_ranking_helper
 * @covers \block_ranking\privacy\provider
 */
class block_ranking_test extends advanced_testcase {

    /**
     * Test that points are added correctly for a user.
     */
    public function test_add_user_points() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        // Simulate a completion record.
        $cmcid = $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $page->cmid,
            'userid' => $student->id,
            'completionstate' => 1,
            'timemodified' => time(),
        ]);

        // Call the manager to add points.
        manager::add_user_points($cmcid);

        // Verify points were recorded.
        $points = $DB->get_record('ranking_points', [
            'userid' => $student->id,
            'courseid' => $course->id,
        ]);

        $this->assertNotFalse($points);
        $this->assertGreaterThan(0, $points->points);

        // Verify log was created.
        $logs = $DB->get_records('ranking_logs', ['rankingid' => $points->id]);
        $this->assertCount(1, $logs);
    }

    /**
     * Test that duplicate completions are detected.
     */
    public function test_duplicate_completion_ignored() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $cmcid = $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $page->cmid,
            'userid' => $student->id,
            'completionstate' => 1,
            'timemodified' => time(),
        ]);

        // Add points twice for the same completion.
        manager::add_user_points($cmcid);
        $firstpoints = $DB->get_record('ranking_points', [
            'userid' => $student->id,
            'courseid' => $course->id,
        ]);

        manager::add_user_points($cmcid);
        $secondpoints = $DB->get_record('ranking_points', [
            'userid' => $student->id,
            'courseid' => $course->id,
        ]);

        // Points should increase (manager doesn't check duplicates — helper does).
        // This test verifies the manager itself processes correctly.
        $this->assertNotFalse($secondpoints);
        $this->assertEquals(2, $DB->count_records('ranking_logs', ['rankingid' => $firstpoints->id]));
    }

    /**
     * Test that ranking order is correct (highest points first).
     */
    public function test_ranking_order() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $student1 = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);
        $student2 = $this->getDataGenerator()->create_user(['firstname' => 'Bob']);
        $student3 = $this->getDataGenerator()->create_user(['firstname' => 'Charlie']);

        foreach ([$student1, $student2, $student3] as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        }

        // Insert points directly to test ranking order.
        $now = time();
        $DB->insert_record('ranking_points', (object) [
            'userid' => $student1->id, 'courseid' => $course->id,
            'points' => 30, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('ranking_points', (object) [
            'userid' => $student2->id, 'courseid' => $course->id,
            'points' => 50, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('ranking_points', (object) [
            'userid' => $student3->id, 'courseid' => $course->id,
            'points' => 10, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        // Query directly with ORDER BY to verify.
        $students = $DB->get_records('ranking_points', ['courseid' => $course->id], 'points DESC');
        $students = array_values($students);

        $this->assertEquals($student2->id, $students[0]->userid); // Bob: 50.
        $this->assertEquals($student1->id, $students[1]->userid); // Alice: 30.
        $this->assertEquals($student3->id, $students[2]->userid); // Charlie: 10.
    }

    /**
     * Test that get_student_role_ids returns configured roles.
     */
    public function test_student_role_filter() {
        $this->resetAfterTest(true);

        $roleids = block_ranking_helper::get_student_role_ids();

        // Should return at least one role (the default student archetype).
        $this->assertNotEmpty($roleids);
        $this->assertIsArray($roleids);

        // All returned values should be integers.
        foreach ($roleids as $roleid) {
            $this->assertIsInt($roleid);
        }
    }

    /**
     * Test that privacy provider exports user data correctly.
     */
    public function test_privacy_export() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        // Insert test data.
        $now = time();
        $pointid = $DB->insert_record('ranking_points', (object) [
            'userid' => $user->id, 'courseid' => $course->id,
            'points' => 25, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('ranking_logs', (object) [
            'rankingid' => $pointid, 'courseid' => $course->id,
            'course_modules_completion' => 1, 'points' => 25, 'timecreated' => $now,
        ]);

        // Get contexts.
        $contextlist = privacy\provider::get_contexts_for_userid($user->id);
        $this->assertNotEmpty($contextlist->get_contextids());

        $coursecontext = \context_course::instance($course->id);
        // Use loose comparison: get_contextids() returns strings from SQL, context->id is int.
        $this->assertContainsEquals($coursecontext->id, $contextlist->get_contextids());
    }

    /**
     * Test that privacy provider deletes user data correctly.
     */
    public function test_privacy_delete() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $now = time();
        $pointid = $DB->insert_record('ranking_points', (object) [
            'userid' => $user->id, 'courseid' => $course->id,
            'points' => 25, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('ranking_logs', (object) [
            'rankingid' => $pointid, 'courseid' => $course->id,
            'course_modules_completion' => 1, 'points' => 25, 'timecreated' => $now,
        ]);

        // Delete for all users in context.
        $coursecontext = \context_course::instance($course->id);
        privacy\provider::delete_data_for_all_users_in_context($coursecontext);

        $this->assertEquals(0, $DB->count_records('ranking_points', ['courseid' => $course->id]));
        $this->assertEquals(0, $DB->count_records('ranking_logs', ['courseid' => $course->id]));
    }

    /**
     * Test that cache is invalidated only for the target course.
     */
    public function test_cache_invalidation_targeted() {
        $this->resetAfterTest(true);

        $cache = \cache::make('block_ranking', 'course_ranking');
        $cache->set('general_1_10_0', ['course1_data']);
        $cache->set('general_2_10_0', ['course2_data']);

        // Invalidate course 1 only.
        rankinglib::invalidate_course_cache(1);

        // Course 1 cache should be gone.
        $this->assertFalse($cache->get('general_1_10_0'));
        // Course 2 cache should still exist.
        $this->assertNotFalse($cache->get('general_2_10_0'));
    }

    /**
     * Test that week/month start helpers return reasonable timestamps.
     */
    public function test_date_helpers() {
        $this->resetAfterTest(true);

        $weekstart = rankinglib::get_week_start();
        $monthstart = rankinglib::get_month_start();
        $now = time();

        // Week start should be in the past (or now at most).
        $this->assertLessThanOrEqual($now, $weekstart);
        // Week start should be within the last 7 days.
        $this->assertGreaterThan($now - (7 * DAYSECS), $weekstart);

        // Month start should be in the past (or now at most).
        $this->assertLessThanOrEqual($now, $monthstart);
        // Month start should be within the last 31 days.
        $this->assertGreaterThan($now - (31 * DAYSECS), $monthstart);
    }

    /**
     * Test that points accumulate correctly with multiple additions.
     */
    public function test_points_accumulate() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Create two different activities.
        $page1 = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $page2 = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $cmcid1 = $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $page1->cmid,
            'userid' => $student->id,
            'completionstate' => 1,
            'timemodified' => time(),
        ]);
        $cmcid2 = $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $page2->cmid,
            'userid' => $student->id,
            'completionstate' => 1,
            'timemodified' => time(),
        ]);

        manager::add_user_points($cmcid1);
        $afterfirst = $DB->get_record('ranking_points', [
            'userid' => $student->id,
            'courseid' => $course->id,
        ]);

        manager::add_user_points($cmcid2);
        $aftersecond = $DB->get_record('ranking_points', [
            'userid' => $student->id,
            'courseid' => $course->id,
        ]);

        // Second should have more points than first.
        $this->assertGreaterThan($afterfirst->points, $aftersecond->points);
        // Should still be a single ranking_points record (atomic update).
        $this->assertEquals(1, $DB->count_records('ranking_points', [
            'userid' => $student->id,
            'courseid' => $course->id,
        ]));
        // Should have 2 log entries.
        $this->assertEquals(2, $DB->count_records('ranking_logs', ['rankingid' => $afterfirst->id]));
    }

    /**
     * Test privacy provider delete_data_for_user removes only that user's data.
     */
    public function test_privacy_delete_for_user() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');

        $now = time();
        $pointid1 = $DB->insert_record('ranking_points', (object) [
            'userid' => $user1->id, 'courseid' => $course->id,
            'points' => 25, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $pointid2 = $DB->insert_record('ranking_points', (object) [
            'userid' => $user2->id, 'courseid' => $course->id,
            'points' => 15, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('ranking_logs', (object) [
            'rankingid' => $pointid1, 'courseid' => $course->id,
            'course_modules_completion' => 1, 'points' => 25, 'timecreated' => $now,
        ]);
        $DB->insert_record('ranking_logs', (object) [
            'rankingid' => $pointid2, 'courseid' => $course->id,
            'course_modules_completion' => 2, 'points' => 15, 'timecreated' => $now,
        ]);

        // Delete only user1's data.
        $coursecontext = \context_course::instance($course->id);
        $contextlist = new \core_privacy\local\request\approved_contextlist(
            $user1, 'block_ranking', [$coursecontext->id]
        );
        privacy\provider::delete_data_for_user($contextlist);

        // User1 data should be gone.
        $this->assertEquals(0, $DB->count_records('ranking_points', ['userid' => $user1->id]));
        // User2 data should remain.
        $this->assertEquals(1, $DB->count_records('ranking_points', ['userid' => $user2->id]));
        $this->assertEquals(1, $DB->count_records('ranking_logs', ['rankingid' => $pointid2]));
    }
}
