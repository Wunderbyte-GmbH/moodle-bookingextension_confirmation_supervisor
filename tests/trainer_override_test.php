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
 * TARGET-BEHAVIOUR tests for options using BOTH confirmation workflows
 * (confirmationtrainerenabled = 1 AND confirmationsupervisorenabled = 1..5, both subplugins
 * enabled site-wide). The functionality is NOT implemented yet, so several of these tests
 * are EXPECTED TO FAIL - they are the executable spec for the feature:
 *
 * 1. A trainer is identified by mod/booking:bookforothers. A PE may also hold that
 *    capability, so the ONLY separator is the HR users list
 *    (confirmation_supervisor_hrusers): in the list => PE, not in the list => trainer.
 * 2. A trainer's confirmation books the answer INSTANTLY - at any stage, for every
 *    supervisor order, even in the 2-step flows (orders 2/4).
 * 3. A PE always follows the supervisor workflow order; their bookforothers must not route
 *    them through the trainer path (today it does: a PE confirming out of turn is
 *    auto-accepted via the trainer plugin - that is the bug these tests pin).
 *
 * @package    bookingextension_confirmation_supervisor
 * @category   test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class trainer_override_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        // On MariaDB, phpunit resets auto-increments so every test reuses identical ids.
        // Without destroying the singletons, cached users/answers/settings from a previous
        // test (same ids!) leak into the next one and distort the confirmation counts.
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * A trainer (bookforothers, not in the HR list, no supervisor relation) books instantly
     * with a single confirmation - for every supervisor order and at every stage, even when
     * the supervisor workflow alone would require two confirmations.
     *
     * @param int $order confirmationsupervisorenabled of the option
     * @param bool $afterfirststep perform the legitimate first step of the 2-step flow first
     *
     * @dataProvider trainer_instant_accept_provider
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_trainer_instant_accept(int $order, bool $afterfirststep): void {
        $env = $this->setup_environment($order, 'bookmyteam');
        $student1 = $env['student1'];
        $trainer1 = $env['trainer1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        if ($afterfirststep) {
            // Perform the legitimate first step of the 2-step flow.
            if ($order == 2) {
                // Order 2: PE first.
                $this->setUser($env['pe1']);
            } else {
                // Order 4: supervisor first (bookmyteam mode -> supervisor workflow).
                $this->setUser($env['supervisor1']);
            }
            $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
            $this->assertEquals(1, $result['success'], 'Precondition: the first step of the 2-step flow must succeed.');
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id, 'Precondition: one of two confirmations done.');
        }

        // The trainer sees the row with the thumbs-up.
        $this->setUser($trainer1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table, 'The trainer must see the pending answer.');
        $this->assertStringContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'The trainer must get the confirm button at any stage of any order.'
        );

        // One trainer confirmation books the answer instantly.
        $sink = $this->redirectEvents();
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success'], 'The trainer must be able to confirm.');

        // The confirmed event logs who confirmed whom and which workflow granted it.
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_booking\event\bookinganswer_confirmed
        ));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertEquals($trainer1->id, $events[0]->userid);
        $this->assertEquals($student1->id, $events[0]->relateduserid);
        $this->assertEquals('confirmation_trainer', $events[0]->other['approvedby']);
        $this->assertEquals($answer->baid, $events[0]->other['baid']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            "TARGET: a trainer confirmation books instantly (order {$order}), even in a 2-step supervisor flow."
        );

        // The booked answer is no longer rendered.
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNull($table, 'The booked answer must not be rendered in the listtoapprove anymore.');
    }

    /**
     * Data provider: all orders at count 0, plus the 2-step orders after the first step.
     *
     * @return array[]
     */
    public static function trainer_instant_accept_provider(): array {
        return [
            'order 1 (supervisor only), count 0' => [1, false],
            'order 2 (PE then supervisor), count 0' => [2, false],
            'order 2 (PE then supervisor), after PE step' => [2, true],
            'order 3 (PE only), count 0' => [3, false],
            'order 4 (supervisor then PE), count 0' => [4, false],
            'order 4 (supervisor then PE), after supervisor step' => [4, true],
            'order 5 (supervisor or PE), count 0' => [5, false],
        ];
    }

    /**
     * HR-list-only classification: a supervisor who holds bookforothers (and is not in the
     * HR list) counts as trainer for a both-flags option and books instantly - even at
     * order 2 count 0, where the supervisor workflow alone would say "PE first".
     *
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_full_supervisor_acts_as_trainer(): void {
        $env = $this->setup_environment(2, 'full');
        $student1 = $env['student1'];
        $supervisor1 = $env['supervisor1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        $this->setUser($supervisor1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table);
        $this->assertStringContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'A supervisor holding bookforothers counts as trainer and must get the confirm button.'
        );

        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'TARGET: a supervisor with bookforothers is a trainer and books instantly (HR-list-only rule).'
        );
    }

    /**
     * PE at order 1 (supervisor only): PE is not part of the order, so despite holding
     * bookforothers they must NOT be routed through the trainer path - no button, refusal,
     * answer untouched. (Today: auto-accepted and booked via the trainer plugin.)
     *
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_pe_refused_order1(): void {
        $env = $this->setup_environment(1, 'bookmyteam');
        $student1 = $env['student1'];
        $pe1 = $env['pe1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        $this->setUser($pe1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        if ($table !== null) {
            $this->assertStringNotContainsString(
                "confirmbooking-username-{$student1->username}",
                $html,
                'TARGET: PE (HR list) must not get a confirm button for a supervisor-only option.'
            );
        }

        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(
            0,
            $result['success'],
            'TARGET: PE must not be auto-accepted via the trainer path on a supervisor-only option.'
        );
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
    }

    /**
     * PE at order 2 (PE then supervisor): the first PE confirmation is step 1 and the answer
     * stays on the waiting list; a SECOND PE confirmation must be refused (today the second
     * attempt is auto-accepted via the trainer path and PE books alone). The bookmyteam
     * supervisor then completes the flow.
     *
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_pe_two_step_order2(): void {
        $env = $this->setup_environment(2, 'bookmyteam');
        $student1 = $env['student1'];
        $pe1 = $env['pe1'];
        $supervisor1 = $env['supervisor1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        // Step 1: PE confirms - answer stays on the waiting list.
        $this->setUser($pe1);
        $sink = $this->redirectEvents();
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success'], 'PE must be able to perform step 1 of order 2.');

        // The confirmed event logs that the SUPERVISOR workflow (not the trainer) granted PE's step.
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_booking\event\bookinganswer_confirmed
        ));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertEquals($pe1->id, $events[0]->userid);
        $this->assertEquals($student1->id, $events[0]->relateduserid);
        $this->assertEquals('confirmation_supervisor', $events[0]->other['approvedby']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ONWAITINGLIST,
            $id,
            'TARGET: the PE confirmation is only step 1 of 2 - it must not book automatically.'
        );

        // A second PE confirmation must be refused.
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(
            0,
            $result['success'],
            'TARGET: PE must not supply the second confirmation via the trainer path.'
        );
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // The bookmyteam supervisor completes the flow.
        $this->setUser($supervisor1);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success'], 'The supervisor must complete step 2 of order 2.');
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * PE at order 3 (PE only): one PE confirmation books the answer. Guard - already true.
     *
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_pe_only_order3(): void {
        $env = $this->setup_environment(3, 'bookmyteam');
        $student1 = $env['student1'];
        $pe1 = $env['pe1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        $this->setUser($pe1);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * PE at order 4 (supervisor then PE): at count 0 it is the supervisor's turn - PE must
     * see the waiting hint and be refused (today: approved via the trainer path). After the
     * bookmyteam supervisor's step, PE completes the flow.
     *
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_pe_order4_must_wait_for_supervisor(): void {
        $env = $this->setup_environment(4, 'bookmyteam');
        $student1 = $env['student1'];
        $pe1 = $env['pe1'];
        $supervisor1 = $env['supervisor1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        // Count 0: PE must wait for the supervisor.
        $this->setUser($pe1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table);
        $this->assertStringContainsString(
            get_string('needsconfirmationofsupervisor', 'bookingextension_confirmation_supervisor'),
            $html,
            'TARGET: PE should see the hint that the supervisor has to confirm first.'
        );
        $this->assertStringNotContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'TARGET: PE must not get a confirm button while it is the supervisor\'s turn.'
        );
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(
            0,
            $result['success'],
            'TARGET: PE must not confirm out of turn via the trainer path.'
        );
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // The bookmyteam supervisor performs step 1.
        $this->setUser($supervisor1);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Now it is PE's turn: PE completes the flow.
        $this->setUser($pe1);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success'], 'PE must complete step 2 of order 4.');
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * PE at order 5 (supervisor or PE): one PE confirmation books the answer. Guard.
     *
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_pe_or_supervisor_order5(): void {
        $env = $this->setup_environment(5, 'bookmyteam');
        $student1 = $env['student1'];
        $pe1 = $env['pe1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        $this->setUser($pe1);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * The bookmyteam supervisor never counts as trainer: at order 2 count 0 they must see
     * the "HR has to confirm" hint and be refused; at order 4 their step 1 leaves the answer
     * on the waiting list and a second attempt is refused. A bookmyteam holder without any
     * relation is refused everywhere.
     *
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_bookmyteam_supervisor_follows_order(): void {
        // Order 2: supervisor must wait for PE.
        $env = $this->setup_environment(2, 'bookmyteam');
        $student1 = $env['student1'];
        $supervisor1 = $env['supervisor1'];
        $outsiderbmt = $env['outsiderbmt'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        $this->setUser($supervisor1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table);
        $this->assertStringContainsString(
            get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor'),
            $html,
            'TARGET: the bookmyteam supervisor should see the hint that HR (PE) has to confirm first.'
        );
        $this->assertStringNotContainsString("confirmbooking-username-{$student1->username}", $html);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(0, $result['success']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // A bookmyteam holder without any supervisor relation is refused as well.
        $this->setUser($outsiderbmt);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(
            0,
            $result['success'],
            'bookmyteam without any relation must never be accepted - not even via the trainer path.'
        );
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
    }

    /**
     * Order 4 for the bookmyteam supervisor: step 1 leaves the answer on the waiting list
     * (no instant booking - they are not a trainer), and a second attempt by the same
     * supervisor is refused.
     *
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_bookmyteam_supervisor_order4_step_only(): void {
        $env = $this->setup_environment(4, 'bookmyteam');
        $student1 = $env['student1'];
        $supervisor1 = $env['supervisor1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        // Step 1 by the supervisor: answer stays on the waiting list.
        $this->setUser($supervisor1);
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ONWAITINGLIST,
            $id,
            'A bookmyteam supervisor is not a trainer - their step 1 must not book instantly.'
        );

        // A second attempt by the same supervisor is refused.
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(0, $result['success']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
    }

    /**
     * Creates course, users, roles, configs and a booking option using BOTH workflows
     * (trainer flag on, supervisor order as given), and books student1 onto the waiting list.
     *
     * @param int $order confirmationsupervisorenabled of the option (1..5)
     * @param string $supervisormode 'full' => supervisor1 gets bookforothers (counts as trainer),
     *                               'bookmyteam' => supervisor1 gets bookmyteam only (supervisor workflow)
     * @return array
     */
    private function setup_environment(int $order, string $supervisormode): array {
        global $CFG;

        if (!\core_component::get_component_directory('bookingextension_confirmation_trainer')) {
            $this->markTestSkipped('Subplugin confirmation_trainer is not available.');
        }

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $this->setAdminUser();

        // BOTH workflows enabled site-wide.
        set_config('confirmationtrainerenabled', 1, 'bookingextension_confirmation_trainer');
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
        $pe1 = $this->getDataGenerator()->create_user();
        $trainer1 = $this->getDataGenerator()->create_user();
        $outsiderbmt = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        // Student 1 reports to supervisor 1.
        profile_save_data((object)['id' => $student1->id, 'profile_field_supervisor' => $supervisor1->id]);
        $this->assertEquals($supervisor1->id, profile_user_record($student1->id)->supervisor);

        // The HR users list is the ONLY thing separating a PE from a trainer.
        set_config('confirmation_supervisor_hrusers', "{$pe1->id}", 'bookingextension_confirmation_supervisor');

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'cancancelbook' => 1,
        ]);

        // Booking option using BOTH workflows.
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Test option both workflows',
            'courseid' => $course->id,
            'chooseorcreatecourse' => 1,
            'waitforconfirmation' => 1,
            'confirmationtrainerenabled' => 1,
            'confirmationsupervisorenabled' => $order,
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        $syscontext = \context_system::instance();

        // Approver role: bookforothers + readresponses (trainer / PE / full-mode supervisor).
        $approverroleid = create_role('Approver', 'approver', 'Approver with bookforothers');
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        assign_capability('mod/booking:readresponses', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        role_assign($approverroleid, $pe1->id, $syscontext->id);
        role_assign($approverroleid, $trainer1->id, $syscontext->id);

        // Team-only role: bookmyteam + readresponses (never a trainer).
        $teamonlyroleid = create_role('Teamonly', 'teamonly', 'Supervisor with bookmyteam instead of bookforothers');
        assign_capability('mod/booking:bookmyteam', CAP_ALLOW, $teamonlyroleid, SYSCONTEXTID, true);
        assign_capability('mod/booking:readresponses', CAP_ALLOW, $teamonlyroleid, SYSCONTEXTID, true);
        role_assign($teamonlyroleid, $outsiderbmt->id, $syscontext->id);

        if ($supervisormode === 'full') {
            role_assign($approverroleid, $supervisor1->id, $syscontext->id);
        } else {
            role_assign($teamonlyroleid, $supervisor1->id, $syscontext->id);
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
            'pe1' => $pe1,
            'trainer1' => $trainer1,
            'outsiderbmt' => $outsiderbmt,
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
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/trainer_override_test.php'));

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
