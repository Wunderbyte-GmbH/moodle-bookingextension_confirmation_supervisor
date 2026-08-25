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

namespace bookingextension_confirmation_supervisor;

use advanced_testcase;
use mod_booking\shortcodes;
use mod_booking\singleton_service;
use mod_booking\booking_bookit;
use mod_booking\bo_availability\bo_info;
use mod_booking\table\manageusers_table;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use stdClass;

/**
 * Tests the rendered output of the [listtoapprove] shortcode for the confirmation order
 * "first HR (PE), then supervisor" (confirmationsupervisorenabled = 2).
 *
 * @package    bookingextension_confirmation_supervisor
 * @category   test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class listtoapprove_confirmation_order_test extends advanced_testcase {
    /**
     * Confirmation order "HR first, then supervisor" (value 2):
     * The [listtoapprove] shortcode must render the answer for the supervisor already
     * BEFORE HR has confirmed, but the supervisor must not be able to confirm it yet.
     * After HR has confirmed, the shortcode must still render it and the supervisor can confirm.
     * After both confirmations, the answer is not rendered anymore.
     *
     * @covers \mod_booking\shortcodes::listtoapprove
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_supervisor_sees_answer_before_hr_confirmation(): void {
        $env = $this->setup_environment('full');
        $student1 = $env['student1'];
        $supervisor1 = $env['supervisor1'];
        $hr1 = $env['hr1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        /*********************************************
         * Step 1: Nobody has confirmed yet (confirmationcount = 0).
         * The [listtoapprove] shortcode already renders the answer for the supervisor...
         *********************************************/
        $this->setUser($supervisor1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull(
            $table,
            'With order "HR first, then supervisor" the listtoapprove shortcode should render '
            . 'a table for the supervisor before HR confirmed.'
        );
        $this->assertEquals(
            1,
            $table->totalrows,
            'With order "HR first, then supervisor" the supervisor should already see the answer before HR confirmed.'
        );
        $usersids = array_map(fn($record) => $record->userid, $table->rawdata);
        $this->assertContains($student1->id, $usersids);

        // The supervisor must see a hint that HR (PE) has to confirm first, and no confirm button.
        $this->assertStringContainsString(
            get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor'),
            $html,
            'The supervisor should see a hint that HR (PE) has to confirm first.'
        );
        $this->assertStringNotContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'The supervisor should not get a confirm button while HR (PE) has not confirmed yet.'
        );

        // ...but must NOT be able to confirm it yet, because HR (PE) has to confirm first.
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(
            0,
            $result['success'],
            'The supervisor must not be able to confirm before HR (PE) has confirmed.'
        );

        // Answer stays on the waiting list.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        /*********************************************
         * Step 2: HR (PE) sees the answer in the shortcode and confirms it (first confirmation).
         *********************************************/
        $this->setUser($hr1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table);
        $this->assertEquals(1, $table->totalrows);
        $usersids = array_map(fn($record) => $record->userid, $table->rawdata);
        $this->assertContains($student1->id, $usersids);

        // It is HR's turn: HR gets the confirm button, not a waiting hint.
        $this->assertStringContainsString("confirmbooking-username-{$student1->username}", $html);

        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success'], 'HR (PE) must be able to confirm first.');

        // Still on waiting list: one of two confirmations done.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        /*********************************************
         * Step 3: Now it is the supervisor's turn.
         * The answer is still rendered in the supervisor's shortcode and can now be confirmed.
         *********************************************/
        $this->setUser($supervisor1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull(
            $table,
            'After the HR (PE) confirmation the listtoapprove shortcode should still render the answer for the supervisor.'
        );
        $this->assertEquals(1, $table->totalrows);
        $usersids = array_map(fn($record) => $record->userid, $table->rawdata);
        $this->assertContains($student1->id, $usersids);

        // Now it is the supervisor's turn: the waiting hint is gone, the confirm button is there.
        $this->assertStringNotContainsString(
            get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor'),
            $html,
            'After the HR (PE) confirmation the waiting hint should be gone for the supervisor.'
        );
        $this->assertStringContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'After the HR (PE) confirmation the supervisor should get the confirm button.'
        );

        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success'], 'The supervisor must be able to confirm after HR (PE).');

        // Both confirmations done: the user is now fully booked.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        /*********************************************
         * Step 4: The fully confirmed answer is no longer on the waiting list,
         * so the shortcode does not render it anymore.
         *********************************************/
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNull(
            $table,
            'After both confirmations the listtoapprove shortcode should not render the answer anymore.'
        );
    }

    /**
     * Reproduces the KSW situation: the supervisor role only has 'local/taskflow:issupervisor'
     * style permissions, i.e. NO mod/booking:readresponses and NO mod/booking:bookforothers.
     * Then the [listtoapprove] shortcode renders an EMPTY list for the supervisor,
     * while an admin (mod/booking:alwayscanapprove via site admin) sees the answer
     * with a confirm button even though it is HR's (PE's) turn.
     *
     * @covers \mod_booking\shortcodes::listtoapprove
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_supervisor_without_booking_capabilities(): void {
        global $USER;

        $env = $this->setup_environment('none');
        $student1 = $env['student1'];
        $supervisor1 = $env['supervisor1'];

        /*********************************************
         * The supervisor lacks mod/booking:readresponses (and bookforothers):
         * the visibility SQL of the listtoapprove table filters everything out,
         * so the supervisor sees NO answers at all.
         *********************************************/
        $this->setUser($supervisor1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNull(
            $table,
            'Without mod/booking:readresponses the supervisor should see an empty listtoapprove.'
        );

        /*********************************************
         * An admin however sees the answer WITH a confirm button (thumbs up),
         * even though it is HR's (PE's) turn: site admins pass the
         * mod/booking:alwayscanapprove short-circuit.
         *********************************************/
        $this->setAdminUser();
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table);
        $this->assertEquals(1, $table->totalrows);
        $this->assertStringContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'An admin gets the confirm button even before HR (PE) confirmed, because of alwayscanapprove.'
        );
        $this->assertStringNotContainsString(
            get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor'),
            $html
        );
    }

    /**
     * The KSW supervisordashboard also embeds the [supervisorteam] shortcode, whose table
     * contains the same confirm column. Its SQL ignores the confirmation order for
     * VISIBILITY (supervisorteam scope), but the confirm column must still respect the
     * order: with order 2 (first HR/PE, then supervisor) and no confirmation yet, the
     * supervisor must see the waiting hint and no confirm button there either.
     *
     * @covers \mod_booking\shortcodes::supervisorteam
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_supervisorteam_shortcode_respects_confirmation_order(): void {
        global $PAGE;

        $env = $this->setup_environment('full');
        $student1 = $env['student1'];
        $supervisor1 = $env['supervisor1'];

        $this->setUser($supervisor1);

        // Use a fresh page, same as in the listtoapprove helper.
        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/listtoapprove_test.php'));

        $envobj = new stdClass();
        $next = function () {
        };
        // Same arguments as the KSW supervisordashboard uses.
        $html = shortcodes::supervisorteam('supervisorteam', ['reduced' => 1, 'cfinclude' => 'typen'], null, $envobj, $next);

        $this->assertNotEmpty($html, 'The supervisorteam shortcode should render the team of the supervisor.');
        // The pending answer of the team member is visible (visibility ignores the order on purpose)...
        $this->assertStringContainsString($student1->firstname, $html);
        // ...but the confirm column must respect the order: waiting hint, no confirm button.
        $this->assertStringContainsString(
            get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor'),
            $html,
            'In the supervisorteam table the supervisor should also see the hint that HR (PE) has to confirm first.'
        );
        $this->assertStringNotContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'In the supervisorteam table the supervisor should not get a confirm button while it is HR\'s (PE\'s) turn.'
        );
    }

    /**
     * Regression test: the confirmation_trainer subplugin being enabled SITE-WIDE must not
     * bypass the confirmation order of options which do not use the trainer workflow
     * (confirmationtrainerenabled = 0 in the option json). Before the fix, its
     * has_capability_to_confirm_booking approved anyone with mod/booking:bookforothers
     * without checking the option json, so a supervisor got a premature confirm button
     * although it was HR's (PE's) turn — and the out-of-order confirmation went through.
     *
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @covers \mod_booking\local\confirmationworkflow\confirmation
     */
    public function test_enabled_trainer_plugin_does_not_break_confirmation_order(): void {
        $env = $this->setup_environment('full');
        $student1 = $env['student1'];
        $supervisor1 = $env['supervisor1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        // The site-wide switch of the trainer subplugin is ON,
        // although THIS option does not use the trainer workflow (confirmationtrainerenabled = 0 in its json).
        set_config('confirmationtrainerenabled', 1, 'bookingextension_confirmation_trainer');

        // It is HR's (PE's) turn — the supervisor must still see the waiting hint, not a confirm button.
        $this->setUser($supervisor1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table);
        $this->assertStringContainsString(
            get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor'),
            $html,
            'The trainer subplugin being enabled site-wide must not override the supervisor workflow order.'
        );
        $this->assertStringNotContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'The supervisor must not get a premature confirm button just because the trainer subplugin is enabled.'
        );

        // And the out-of-order confirmation must be refused.
        $mutable = new manageusers_table('test_optionstoconfirm_0');
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(0, $result['success']);

        // The answer stays untouched on the waiting list.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
    }

    /**
     * A supervisor may hold only the restricted mod/booking:bookmyteam capability instead of
     * the unrestricted mod/booking:bookforothers. That must be enough to confirm for their own
     * team - and the confirmation order must still be enforced for them.
     *
     * @covers \mod_booking\shortcodes::listtoapprove
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_supervisor_with_bookmyteam_only(): void {
        $env = $this->setup_environment('bookmyteam');
        $student1 = $env['student1'];
        $supervisor1 = $env['supervisor1'];
        $hr1 = $env['hr1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        // It is HR's (PE's) turn: the supervisor sees the row and the waiting hint, but no button.
        $this->setUser($supervisor1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table, 'A supervisor with bookmyteam should see the answers of their team.');
        $this->assertEquals(1, $table->totalrows);
        $this->assertStringContainsString(
            get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor'),
            $html,
            'The confirmation order must be enforced for a supervisor holding only bookmyteam.'
        );
        $this->assertStringNotContainsString("confirmbooking-username-{$student1->username}", $html);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(0, $result['success']);

        // HR (PE) confirms first.
        $this->setUser($hr1);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']);

        // Now it is the supervisor's turn: bookmyteam is enough to get the button and confirm.
        $this->setUser($supervisor1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table);
        $this->assertStringContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'mod/booking:bookmyteam must be accepted instead of mod/booking:bookforothers.'
        );
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success'], 'A supervisor with bookmyteam must be able to confirm for their team.');

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * bookmyteam alone must not be enough: the supervisor / deputy relation is still required.
     * The outsider holds bookmyteam but is neither supervisor nor deputy of student1.
     *
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_bookmyteam_without_supervisor_relation(): void {
        $env = $this->setup_environment('full');
        $student1 = $env['student1'];
        $outsider1 = $env['outsider1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        $this->setUser($outsider1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        // No relation to student1, so there is nothing to approve for this user.
        $this->assertNull($table, 'A bookmyteam holder without supervisor relation must not see foreign answers.');
        $this->assertStringNotContainsString("confirmbooking-username-{$student1->username}", $html);

        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(0, $result['success'], 'bookmyteam must not allow confirming for users outside the own team.');

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
    }

    /**
     * Side effect of keeping mod/booking:bookforothers as the gate of the trainer subplugin:
     * a supervisor holding only bookmyteam does not pass the trainer gate, so even on an option
     * with BOTH workflow flags set the supervisor workflow governs and the order is enforced.
     *
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     */
    public function test_bookmyteam_supervisor_on_trainer_flagged_option(): void {
        // Option json has confirmationtrainerenabled = 1 AND confirmationsupervisorenabled = 2.
        $env = $this->setup_environment('bookmyteam', 1);
        $student1 = $env['student1'];
        $supervisor1 = $env['supervisor1'];
        $hr1 = $env['hr1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        // The trainer subplugin is enabled site-wide and the option uses the trainer workflow...
        set_config('confirmationtrainerenabled', 1, 'bookingextension_confirmation_trainer');

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        // ...but the supervisor only holds bookmyteam, so the trainer gate (bookforothers) rejects
        // them and the supervisor workflow decides: it is HR's (PE's) turn, so no button.
        $this->setUser($supervisor1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table);
        $this->assertStringContainsString(
            get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor'),
            $html,
            'A bookmyteam-only supervisor must not slip through the trainer gate.'
        );
        $this->assertStringNotContainsString("confirmbooking-username-{$student1->username}", $html);

        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(0, $result['success']);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // HR (PE) holds bookforothers, so for HR the trainer workflow applies and grants
        // the confirmation regardless of the order - this documents the OR semantics.
        $this->setUser($hr1);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']);
    }

    /**
     * Creates course, users, profile fields, configs and a booking option with
     * confirmation order 2 (first HR/PE, then supervisor), and books student1 onto
     * the waiting list.
     *
     * @param string $supervisorcaps which booking capabilities the supervisor role gets:
     *                               'full'       => bookforothers + readresponses,
     *                               'bookmyteam' => bookmyteam + readresponses (no bookforothers),
     *                               'none'       => no booking capabilities at all.
     * @param int $trainerflag value of confirmationtrainerenabled in the option json
     * @return array
     */
    private function setup_environment(string $supervisorcaps = 'full', int $trainerflag = 0): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $this->setAdminUser();

        set_config('confirmationtrainerenabled', 0, 'bookingextension_confirmation_trainer');
        set_config('confirmationsupervisorenabled', 1, 'bookingextension_confirmation_supervisor');

        // Create the custom profile fields holding the supervisor / deputy relation.
        $this->create_custom_profile_field('supervisor', 'Supervisor');
        $this->create_custom_profile_field('deputy', 'Deputy');
        set_config('supervisor', 'supervisor', 'bookingextension_confirmation_supervisor');
        set_config('deputy', 'deputy', 'bookingextension_confirmation_supervisor');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $supervisor1 = $this->getDataGenerator()->create_user();
        $hr1 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();
        // Holds bookmyteam, but is neither supervisor nor deputy of student1.
        $outsider1 = $this->getDataGenerator()->create_user();

        // Student 1 reports to supervisor 1.
        profile_save_data((object)['id' => $student1->id, 'profile_field_supervisor' => $supervisor1->id]);
        $this->assertEquals($supervisor1->id, profile_user_record($student1->id)->supervisor);

        // HR (PE) users.
        set_config('confirmation_supervisor_hrusers', "{$hr1->id}", 'bookingextension_confirmation_supervisor');

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'cancancelbook' => 1,
        ]);

        // Create booking option: wait for confirmation, order 2 = first HR (PE), then supervisor.
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Test option HR then supervisor',
            'courseid' => $course->id,
            'chooseorcreatecourse' => 1,
            'waitforconfirmation' => 1,
            'confirmationtrainerenabled' => $trainerflag,
            'confirmationsupervisorenabled' => 2,
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        $syscontext = \context_system::instance();

        // Approver role with the minimal capabilities needed to see & confirm answers. HR always gets it.
        // Note: readresponses controls row visibility in the list, bookforothers gates the confirm action.
        $approverroleid = create_role('Approver', 'approver', 'Approver with special booking capabilities');
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        assign_capability('mod/booking:readresponses', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        role_assign($approverroleid, $hr1->id, $syscontext->id);

        // Team-only role: the restricted "book my team" capability instead of bookforothers.
        $teamonlyroleid = create_role('Teamonly', 'teamonly', 'Supervisor with bookmyteam instead of bookforothers');
        assign_capability('mod/booking:bookmyteam', CAP_ALLOW, $teamonlyroleid, SYSCONTEXTID, true);
        assign_capability('mod/booking:readresponses', CAP_ALLOW, $teamonlyroleid, SYSCONTEXTID, true);
        role_assign($teamonlyroleid, $outsider1->id, $syscontext->id);

        switch ($supervisorcaps) {
            case 'full':
                role_assign($approverroleid, $supervisor1->id, $syscontext->id);
                break;
            case 'bookmyteam':
                role_assign($teamonlyroleid, $supervisor1->id, $syscontext->id);
                break;
            default:
                // Like the KSW 'supervisor' role: a system role WITHOUT any mod/booking capabilities.
                $supervisorroleid = create_role('Supervisor', 'testsupervisor', 'Supervisor without booking capabilities');
                role_assign($supervisorroleid, $supervisor1->id, $syscontext->id);
                break;
        }

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');

        // Student 1 books and lands on the waiting list.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        booking_bookit::bookit('option', $settings->id, $student1->id); // Attempt to book.
        booking_bookit::bookit('option', $settings->id, $student1->id); // Confirm to book.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student1->id] ?? null;
        $this->assertNotEmpty($answer);

        return [
            'course' => $course,
            'booking' => $booking,
            'option' => $option,
            'settings' => $settings,
            'boinfo' => $boinfo,
            'answer' => $answer,
            'student1' => $student1,
            'supervisor1' => $supervisor1,
            'hr1' => $hr1,
            'outsider1' => $outsider1,
        ];
    }

    /**
     * Calls the real [listtoapprove] shortcode and returns its HTML output together with
     * the rendered table, re-instantiated from the data-encodedtable hash in the HTML.
     * The table is null if the shortcode did not render one (no answers to confirm).
     *
     * @return array [string $html, ?wunderbyte_table $table]
     */
    private function get_table_from_listtoapprove_shortcode(): array {
        global $PAGE;

        // Use a fresh page for each call, so context & url can be set repeatedly.
        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/listtoapprove_test.php'));

        $env = new stdClass();
        $next = function () {
        };
        // Same arguments as the KSW supervisordashboard uses.
        $html = shortcodes::listtoapprove('listtoapprove', ['reduced' => 1, 'cfinclude' => 'chf'], null, $env, $next);

        // In reduced mode the shortcode returns an empty string when there is nothing to confirm.
        if (trim($html) === '') {
            return ['', null];
        }
        $this->assertStringNotContainsString('alert-warning', $html, 'The shortcode returned an error message.');

        if (!preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $html, $matches)) {
            return [$html, null];
        }

        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        return [$html, $table];
    }

    /**
     * Creates a custom user profile field.
     *
     * @param string $shortname Field shortname (e.g. 'supervisor')
     * @param string $name Field name shown in UI
     * @return void
     */
    private function create_custom_profile_field(string $shortname, string $name): void {
        global $DB;

        if (!$DB->record_exists('user_info_category', ['name' => 'Test Category'])) {
            $cat = new stdClass();
            $cat->name = 'Test Category';
            $cat->sortorder = 1;
            $cat->id = $DB->insert_record('user_info_category', $cat);
        } else {
            $cat = $DB->get_record('user_info_category', ['name' => 'Test Category']);
        }

        $field = new stdClass();
        $field->shortname = $shortname;
        $field->name = $name;
        $field->datatype = 'text';
        $field->description = '';
        $field->descriptionformat = FORMAT_HTML;
        $field->categoryid = $cat->id;
        $field->sortorder = 1;
        $field->required = 0;
        $field->locked = 0;
        $field->visible = 1;
        $field->forceunique = 0;
        $field->signup = 0;
        $field->defaultdata = '';
        $field->defaultdataformat = FORMAT_HTML;
        $field->param1 = 30;
        $field->id = $DB->insert_record('user_info_field', $field);
    }
}
