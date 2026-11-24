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

namespace local_pluginiaconnect;

use local_pluginiaconnect\local\policy\context_filter;
use local_pluginiaconnect\local\policy\eligibility;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\policy\scope;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\eligibility::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\context_filter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\scope::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\profile::class)]
/**
 * Tests of profile eligibility, the site scope filter and scope granting.
 *
 * Users, roles, courses and categories are real: nothing is mocked.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\policy\eligibility
 * @covers     \local_pluginiaconnect\local\policy\context_filter
 * @covers     \local_pluginiaconnect\local\policy\scope
 * @covers     \local_pluginiaconnect\local\policy\profile
 */
final class eligibility_test extends \advanced_testcase {
    /**
     * Enables every profile, so that each test only switches off what it studies.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        foreach (profile::all() as $name) {
            set_config(profile::setting($name), 1, 'local_pluginiaconnect');
        }
    }

    /**
     * Creates a user with a standard role in a new course.
     *
     * @param string|null $role Role shortname in the course, or null for no enrolment.
     * @return array [user, course]
     */
    private function user_in_course(?string $role): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        if ($role !== null) {
            $generator->enrol_user($user->id, $course->id, $role);
        }
        return [$user, $course];
    }

    /**
     * Standard roles and the profiles each one may choose when all are enabled.
     *
     * @return array
     */
    public static function role_provider(): array {
        return [
            'not enrolled' => [null, [profile::STUDENT]],
            'student' => ['student', [profile::STUDENT]],
            'non-editing teacher' => ['teacher', [profile::STUDENT, profile::TEACHER]],
            'editing teacher' => ['editingteacher', [profile::STUDENT, profile::TEACHER]],
            // A manager enrolled in one course holds the teacher capability there, not the category one.
            'course manager' => ['manager', [profile::STUDENT, profile::TEACHER]],
        ];
    }

    /**
     * Each standard course role gets exactly the profiles its capabilities give.
     *
     * @dataProvider role_provider
     * @param string|null $role Role shortname.
     * @param string[] $expected Expected profiles.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('role_provider')]
    public function test_profiles_by_course_role(?string $role, array $expected): void {
        [$user] = $this->user_in_course($role);
        $this->assertSame($expected, eligibility::eligible_profiles($user->id));
    }

    /**
     * A category manager gets the manager profile; a system manager too.
     */
    public function test_manager_profile(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $generator->create_course(['category' => $category->id]);
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        $catmanager = $generator->create_user();
        role_assign($managerrole, $catmanager->id, \context_coursecat::instance($category->id));
        $this->assertSame(
            [profile::STUDENT, profile::TEACHER, profile::MANAGER],
            eligibility::eligible_profiles($catmanager->id)
        );

        $sysmanager = $generator->create_user();
        role_assign($managerrole, $sysmanager->id, \context_system::instance());
        $this->assertContains(profile::MANAGER, eligibility::eligible_profiles($sysmanager->id));
        $this->assertNotContains(profile::ADMIN, eligibility::eligible_profiles($sysmanager->id));
    }

    /**
     * The administrator profile needs the setting, site administration and, if set, the user list.
     */
    public function test_admin_profile(): void {
        $admin = get_admin();
        $this->assertContains(profile::ADMIN, eligibility::eligible_profiles($admin->id));

        set_config('adminprofileusers', '999999', 'local_pluginiaconnect');
        eligibility::invalidate_all();
        $this->assertNotContains(profile::ADMIN, eligibility::eligible_profiles($admin->id));

        set_config('adminprofileusers', '999999,' . $admin->id, 'local_pluginiaconnect');
        eligibility::invalidate_all();
        $this->assertContains(profile::ADMIN, eligibility::eligible_profiles($admin->id));

        set_config('allowadminprofile', 0, 'local_pluginiaconnect');
        eligibility::invalidate_all();
        $this->assertNotContains(profile::ADMIN, eligibility::eligible_profiles($admin->id));

        // A manager is never an administrator, whatever the settings say.
        set_config('allowadminprofile', 1, 'local_pluginiaconnect');
        set_config('adminprofileusers', '', 'local_pluginiaconnect');
        [$teacher] = $this->user_in_course('editingteacher');
        $this->assertNotContains(profile::ADMIN, eligibility::eligible_profiles($teacher->id));
    }

    /**
     * A disabled profile is offered to nobody, including site administrators.
     */
    public function test_disabled_profiles(): void {
        [$teacher] = $this->user_in_course('editingteacher');
        foreach (profile::all() as $name) {
            set_config(profile::setting($name), 0, 'local_pluginiaconnect');
        }
        eligibility::invalidate_all();
        $this->assertSame([], eligibility::eligible_profiles($teacher->id));
        $this->assertSame([], eligibility::eligible_profiles(get_admin()->id));
        $this->assertSame('noprofile', eligibility::connect_denial($teacher));
    }

    /**
     * Factory settings: the student profile is off, so a student cannot connect.
     */
    public function test_factory_defaults_leave_students_out(): void {
        foreach (profile::all() as $name) {
            unset_config(profile::setting($name), 'local_pluginiaconnect');
        }
        eligibility::invalidate_all();
        [$student] = $this->user_in_course('student');
        [$teacher] = $this->user_in_course('editingteacher');
        $this->assertSame([], eligibility::eligible_profiles($student->id));
        $this->assertSame([profile::TEACHER], eligibility::eligible_profiles($teacher->id));
        // A site administrator passes capability checks, but the administrator profile itself stays off.
        $this->assertSame([profile::TEACHER, profile::MANAGER], eligibility::eligible_profiles(get_admin()->id));
    }

    /**
     * Who may connect at all.
     */
    public function test_connect_denial(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $generator = $this->getDataGenerator();
        [$student] = $this->user_in_course('student');
        $this->assertNull(eligibility::connect_denial($student));

        $this->assertSame('guest', eligibility::connect_denial(guest_user()));
        $this->assertSame('guest', eligibility::connect_denial((object) ['id' => 0]));

        $suspended = $generator->create_user(['suspended' => 1]);
        $this->assertSame('inactive', eligibility::connect_denial($suspended));
        $nologin = $generator->create_user(['auth' => 'nologin']);
        $this->assertSame('inactive', eligibility::connect_denial($nologin));

        // A role that prohibits the connect capability.
        $roleid = $generator->create_role();
        assign_capability(eligibility::CAP_CONNECT, CAP_PROHIBIT, $roleid, \context_system::instance());
        $blocked = $generator->create_user();
        role_assign($roleid, $blocked->id, \context_system::instance());
        $this->assertSame('nocapability', eligibility::connect_denial($blocked));

        // Excluded cohort.
        $cohort = $generator->create_cohort();
        cohort_add_member($cohort->id, $student->id);
        set_config('excludedcohorts', $cohort->id, 'local_pluginiaconnect');
        $this->assertSame('cohort', eligibility::connect_denial($student));
        $this->assertTrue($DB->record_exists('cohort_members', ['userid' => $student->id]));
    }

    /**
     * The live check is tied to the context: a teacher in one course is not a teacher in another.
     */
    public function test_live_check_is_per_context(): void {
        $generator = $this->getDataGenerator();
        [$user, $taught] = $this->user_in_course('editingteacher');
        $other = $generator->create_course();
        $generator->enrol_user($user->id, $other->id, 'student');

        $taughtctx = \context_course::instance($taught->id);
        $otherctx = \context_course::instance($other->id);
        $this->assertTrue(eligibility::is_eligible_in_context($user->id, profile::TEACHER, $taughtctx));
        $this->assertFalse(eligibility::is_eligible_in_context($user->id, profile::TEACHER, $otherctx));
        $this->assertTrue(eligibility::is_eligible_in_context($user->id, profile::STUDENT, $otherctx));
        $this->assertFalse(eligibility::is_eligible_in_context($user->id, profile::TEACHER, \context_system::instance()));
        $this->assertFalse(eligibility::is_eligible_in_context($user->id, profile::MANAGER, $taughtctx));
        $this->assertFalse(eligibility::is_eligible_in_context($user->id, profile::ADMIN, $taughtctx));
        $this->assertFalse(eligibility::is_eligible_in_context($user->id, 'root', $taughtctx));

        // Activity contexts resolve to their course.
        $page = $generator->create_module('page', ['course' => $taught->id]);
        $this->assertTrue(eligibility::is_eligible_in_context(
            $user->id,
            profile::TEACHER,
            \context_module::instance($page->cmid)
        ));
    }

    /**
     * Losing the role takes effect in the live check at once, without waiting for the cache.
     */
    public function test_live_check_sees_role_removal(): void {
        global $DB;
        [$user, $course] = $this->user_in_course('editingteacher');
        $context = \context_course::instance($course->id);
        $this->assertTrue(eligibility::is_eligible_in_context($user->id, profile::TEACHER, $context));

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        role_unassign($roleid, $user->id, $context->id);
        $this->assertFalse(eligibility::is_eligible_in_context($user->id, profile::TEACHER, $context));

        // The cached list is refreshed when it is invalidated.
        eligibility::invalidate($user->id);
        $this->assertNotContains(profile::TEACHER, eligibility::eligible_profiles($user->id));
    }

    /**
     * Site scope: allowed and excluded categories, excluded and hidden courses.
     */
    public function test_context_filter(): void {
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $elsewhere = $generator->create_category();
        $inchild = $generator->create_course(['category' => $child->id]);
        $outside = $generator->create_course(['category' => $elsewhere->id]);
        $hidden = $generator->create_course(['category' => $child->id, 'visible' => 0]);

        // No restriction.
        $this->assertTrue(context_filter::is_course_allowed($inchild->id));
        $this->assertTrue(context_filter::is_course_allowed($outside->id));
        $this->assertFalse(context_filter::is_course_allowed($hidden->id));
        $this->assertFalse(context_filter::is_course_allowed(987654));
        $this->assertFalse(context_filter::is_category_allowed(987654));

        set_config('includehiddencourses', 1, 'local_pluginiaconnect');
        $this->assertTrue(context_filter::is_course_allowed($hidden->id));

        // Allowed list covers descendants.
        set_config('allowedcategories', $parent->id, 'local_pluginiaconnect');
        $this->assertTrue(context_filter::is_course_allowed($inchild->id));
        $this->assertFalse(context_filter::is_course_allowed($outside->id));
        $this->assertTrue(context_filter::is_category_allowed($child->id));
        $this->assertFalse(context_filter::is_category_allowed($elsewhere->id));

        // Exclusion wins over inclusion.
        set_config('excludedcategories', $child->id, 'local_pluginiaconnect');
        $this->assertFalse(context_filter::is_course_allowed($inchild->id));
        $this->assertFalse(context_filter::is_context_allowed(\context_course::instance($inchild->id)));
        $this->assertFalse(context_filter::is_context_allowed(\context_coursecat::instance($child->id)));
        $this->assertTrue(context_filter::is_context_allowed(\context_system::instance()));

        set_config('excludedcategories', '', 'local_pluginiaconnect');
        set_config('excludedcourses', $inchild->id, 'local_pluginiaconnect');
        $this->assertFalse(context_filter::is_course_allowed($inchild->id));
        $this->assertSame([(int) $hidden->id], context_filter::filter_courses([$inchild->id, $hidden->id, $outside->id]));
    }

    /**
     * A teacher whose only course is out of scope has no teacher profile.
     */
    public function test_teacher_profile_needs_a_course_in_scope(): void {
        [$user, $course] = $this->user_in_course('editingteacher');
        set_config('excludedcourses', $course->id, 'local_pluginiaconnect');
        eligibility::invalidate_all();
        $this->assertNotContains(profile::TEACHER, eligibility::eligible_profiles($user->id));
        $this->assertFalse(eligibility::can_write_somewhere($user->id));
    }

    /**
     * Granted scopes never exceed profile, access level, write capability or site state.
     */
    public function test_scope_granting(): void {
        [$editing] = $this->user_in_course('editingteacher');
        [$nonediting] = $this->user_in_course('teacher');

        $readonly = scope::granted(null, profile::TEACHER, false, $editing->id);
        $this->assertSame(['profile', 'courses.read', 'grades.read', 'participants.read', 'offline_access'], $readonly);

        $write = scope::granted(null, profile::TEACHER, true, $editing->id);
        $this->assertContains('grades.write', $write);
        $this->assertNotContains('enrol.write', $write);
        $this->assertNotContains('users.write', $write);

        // The non-editing teacher lacks the write capability.
        $this->assertSame($readonly, scope::granted(null, profile::TEACHER, true, $nonediting->id));

        // Read-only mode of the site removes every write scope.
        set_config('readonlymode', 1, 'local_pluginiaconnect');
        $this->assertSame($readonly, scope::granted(null, profile::TEACHER, true, $editing->id));
        set_config('readonlymode', 0, 'local_pluginiaconnect');

        // Requested scopes are intersected, never added to.
        $asked = scope::granted(['courses.read', 'site.read', 'users.write', 'bogus'], profile::TEACHER, true, $editing->id);
        $this->assertSame(['courses.read'], $asked);

        // Scopes without an enabled tool are dropped, except identity and refresh.
        $active = scope::granted(null, profile::TEACHER, true, $editing->id, ['courses.read']);
        $this->assertSame(['profile', 'courses.read', 'offline_access'], $active);

        $this->assertSame([], scope::granted(null, 'root', true, $editing->id));
        $this->assertSame(['profile', 'courses.read'], scope::parse(" profile  courses.read profile nope\t"));
        $this->assertTrue(scope::is_write('enrol.write'));
        $this->assertFalse(scope::is_write('nope'));
        $this->assertSame(profile::ADMIN, scope::minimum_profile('site.read'));
        $this->assertFalse(profile::at_least('root', profile::STUDENT));
        $this->assertFalse(profile::at_least(profile::ADMIN, 'root'));
        $this->assertTrue(profile::at_least(profile::MANAGER, profile::TEACHER));
    }
}
