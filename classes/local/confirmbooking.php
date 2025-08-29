<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Class confirmation_supervisor.
 *
 * @package     bookingextension_confirmation_supervisor
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_confirmation_supervisor\local;

use mod_booking\local\interfaces\bookingextension\confirmbooking_interface;
use context_module;
use mod_booking\singleton_service;

/**
 * Class to confirmbookings
 */
class confirmbooking implements confirmbooking_interface {
    /**
     * A subplugin can implement it's own way to add ways to allow supervisors to approve requests on waitinglist.
     * If the first value in the aray is true, this means that the test was successful.
     *
     * @param int $optionid
     * @param int $approverid
     * @param int $userid
     *
     * @return array // Returns [false, 'Reason why you are not allowed to book', false] // where last value is reload.
     *
     */
    public static function has_capability_to_confirm_booking(int $optionid, int $approverid, int $userid): array {
        global $USER, $DB;
        $approved = false;
        $message = get_string('notallowedtoconfirm', 'bookingextension_confirmation_supervisor');
        $reload = false;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);
        if (!has_capability('mod/booking:bookforothers', $context)) {
            return [$approved, $message, $reload]; // Can not approve.
        }

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $bookinganswer = ($bookinganswers->get_usersonwaitinglist())[$userid] ?? null;
        if (empty($bookinganswer)) {
            $message = 'Nothing to confirm';
            return [$approved, $message, $reload]; // Can not approve.
        }
        // Get current number of confirmation.
        $confirmationcount = (!empty($bookinganswer->json)) ?
            (json_decode($bookinganswer->json))->confirmationcount ?? 0 : 0;
        // Get list of users ids of approvers who currently confirmed.
        $confirmationby = (!empty($bookinganswer->json)) ?
            (json_decode($bookinganswer->json))->confirmwaitinglist_modifieduserid ?? [] : [];
        // Get User ID of the last approver.
        $lastconfirmationby = count($confirmationby) ? end($confirmationby) : 0;

        $bosettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (!property_exists($bosettings->jsonobject, 'confirmationsupervisorenabled')) {
            return [$approved, $message, $reload]; // Can not approve.
        }

        $confirmationsupervisorenabled = (int) $bosettings->jsonobject->confirmationsupervisorenabled;

        if (self::is_hr($approverid)) {
            // We need to check value of confirmationsupervisorenabled. HR should see 2, 3, 4, 5.
            // For type 2 (HR → supervisor), show only if confirmationcount = 0 in booking answer record.
            // For type 4 (supervisor → HR), show only if confirmationcount = 1 in booking answer record.
            if (!in_array($confirmationsupervisorenabled, [2, 3, 4, 5])) {
                // Cannot approve, because based on confirmationsupervisorenabled settings,
                // HR is not allowed to confirm the answers of this booking option.
                return [$approved, $message, $reload];
            }

            if ($confirmationsupervisorenabled === 4 && $confirmationcount == 0) {
                // Cannot approve, because the booking answer is not confirmed by supervisor yet and
                // HR must wait for confirmartion of supervisor.
                $message = get_string('needsconfirmationofsupervisor', 'bookingextension_confirmation_supervisor');
                return [$approved, $message, $reload];
            }

            if (
                (in_array($confirmationsupervisorenabled, [2, 3]) && $confirmationcount >= 1)
                ||
                (in_array($confirmationsupervisorenabled, [4]) && $confirmationcount >= 2)
            ) {
                // Cannot approve, because HR confirmed the booking answer previously.
                $message = get_string('alreadyconfirmed', 'bookingextension_confirmation_supervisor');
                return [$approved, $message, $reload];
            }

            if (
                (in_array($confirmationsupervisorenabled, [5]) && $confirmationcount >= 1)
            ) {
                // Check who has confirmed the ansewr, HR or supervisor, to return meaningful message.
                if ($lastconfirmationby == $USER->id) {
                    // Cannot approve, because HR confirmed the booking answer previously.
                    $message = get_string('alreadyconfirmed', 'bookingextension_confirmation_supervisor');
                    return [$approved, $message, $reload];
                } else {
                    // Cannot approve, because Supervisor confirmed the booking answer previously.
                    $message = get_string('alreadyconfirmedbysupervisor', 'bookingextension_confirmation_supervisor');
                    return [$approved, $message, $reload];
                }
            }
        } else {
            // Check if user user is supervisor (or deputy of supervisor) for the related user of the booking
            // answer record, then check if allowed to confirm, and check if his/her right in confirmation order.
            if (
                !self::is_supervisor($approverid, $userid)
                && !self::is_deputy($approverid, $userid)
            ) {
                // Canot approve because the user id of supervisor is not defined in the user profile
                // of the user who owns the booking answer.
                return [$approved, $message, $reload]; // Can not approve.
            }
            // We need to check value of confirmationsupervisorenabled. HR should see 1, 2, 4, 5.
            // For type 2 (HR → supervisor), show only if confirmationcount = 1 in booking answer record.
            // For type 4 (supervisor → HR), show only if confirmationcount = 0 in booking answer record.
            if (!in_array($confirmationsupervisorenabled, [1, 2, 4, 5])) {
                // Cannot approve, because based on confirmationsupervisorenabled settings,
                // Supervisor is not allowed to confirm the answers of this booking option.
                return [$approved, $message, $reload];
            }

            if ($confirmationsupervisorenabled === 2 && $confirmationcount == 0) {
                // Cannot approve, because supervisor confirmed the booking answer previously.
                $message = get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor');
                return [$approved, $message, $reload]; // Can not approve.
            }

            if (
                (in_array($confirmationsupervisorenabled, [1, 4]) && $confirmationcount >= 1)
                ||
                (in_array($confirmationsupervisorenabled, [2]) && $confirmationcount >= 2)
            ) {
                // Cannot approve, because HR confirmed the booking answer previously.
                $message = get_string('alreadyconfirmed', 'bookingextension_confirmation_supervisor');
                return [$approved, $message, $reload];
            }

            if (
                (in_array($confirmationsupervisorenabled, [5]) && $confirmationcount >= 1)
            ) {
                // Check who has confirmed the ansewr, HR or supervisor, to return meaningful message.
                if ($lastconfirmationby == $USER->id) {
                    // Cannot approve, because Supervisor confirmed the booking answer previously.
                    $message = get_string('alreadyconfirmed', 'bookingextension_confirmation_supervisor');
                    return [$approved, $message, $reload];
                } else {
                    // Cannot approve, because HR confirmed the booking answer previously.
                    $message = get_string('alreadyconfirmedbyhr', 'bookingextension_confirmation_supervisor');
                    return [$approved, $message, $reload];
                }
            }
        }

        $approved = true;
        $message = '';
        return [$approved, $message, $reload]; // Can approve.
    }

    /**
     * Function to return the name of the workflow.
     *
     * @return string
     *
     */
    public function get_name(): string {
        return get_string('workflowname', 'bookingextension_confirmation_supervisor');
    }

    /**
     * Function to return a detailed description of the workflow.
     *
     * @return string
     *
     */
    public function get_description(): string {
        return get_string('workflowdescription', 'bookingextension_confirmation_supervisor');
    }

    /**
     * This returns the sql corresponding to the right settings.
     * When adding params, make sure the don't interfer.
     * Prefix them with bec (bookingextension_confirmation), eg bectuserid or becsuserid.
     * @param array $params
     *
     * @return string
     *
     */
    public function return_where_sql(array &$params): string {
        $sql = '';

        global $DB;

        $driver = $DB->get_dbfamily();

        switch ($driver) {
            case 'postgres':
                $sql = $this->return_where_sql_postgres($params);
                break;
            case 'mysql':
                $sql = $this->return_where_sql_mysql($params);
                break;
            default: // Fallback.
                throw new \moodle_exception('Unsupported DB driver: ' . $driver);
        }

        return $sql;
    }

    /**
     * This returns the sql corresponding to the right settings.
     * When adding params, make sure the don't interfer.
     * Prefix them with bec (bookingextension_confirmation), eg bectuserid or becsuserid.
     * @param array $params
     *
     * @return string
     *
     */
    public function return_where_sql_postgres(array &$params): string {

        global $USER;

        // The logic needs to be like this.

        // Depending on the chosen setting in the column json,
        // we either verify that the current user is a supervisor
        // or the user is a HR
        // and we need first confirmation
        // or we need second confirmation.

        // Actually, i guess supervisors should see the need for confirmation from HR and HR from supervisor
        // so probably that should not even be an issue.

        // So we just need to make sure the user is allowed to see the settings.
        // The supervisor confirmation goes on hr, supervisorfield and deputy field.
        // The trainer approval goes on being trainer for a given booking option.
        // (might also need to check the context capabilities on all booking instances, when we think of it).

        $supervisorfieldshortname = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $deputyfieldshortname = get_config('bookingextension_confirmation_supervisor', 'deputy');

        $hrids = explode(
            ',',
            get_config('bookingextension_confirmation_supervisor', 'confirmation_supervisor_hrusers')
        );
        $ishr = in_array($USER->id, $hrids);

        // Params.
        $params['becssupervisorid'] = $USER->id;
        $params['becssupervisorfieldshortname'] = $supervisorfieldshortname;
        $params['becssupervisorfieldshortname2'] = $supervisorfieldshortname;
        $params['becsdeputyid'] = $USER->id;
        $params['becsdeputyfieldshortname'] = $deputyfieldshortname;

         // Core JSON confirmation field.
        $waitforconfirmation = "bo.json::jsonb ->> 'waitforconfirmation' > '0'";

        if ($ishr) {
            // HR should see 2, 3, 4.
            $conflevel = "bo.json::jsonb ->> 'confirmationsupervisorenabled' IN ('2','3','4','5')";

            return "$waitforconfirmation AND $conflevel";
        } else {
            // Supervisors should see 1, 2, 4 — but only if they're linked via profile field.
            $conflevel = "bo.json::jsonb ->> 'confirmationsupervisorenabled' IN ('1','2','4','5')";
            $supervisorcond = "EXISTS (
                SELECT 1
                FROM {user_info_data} uid
                JOIN {user_info_field} uif ON uid.fieldid = uif.id
                WHERE uif.shortname = :becssupervisorfieldshortname
                AND uid.userid = u.id
                AND (',' || uid.data || ',' LIKE '%,' || :becssupervisorid || ',%')
            )";
            $deputycond = "EXISTS (
                SELECT 1
                FROM {user_info_data} uid
                JOIN {user_info_field} uif ON uid.fieldid = uif.id
                WHERE uif.shortname = :becssupervisorfieldshortname2
                AND uid.userid = u.id
                AND (',' || uid.data || ',' LIKE '%,' ||
                    (
                        SELECT uid3.userid
                        FROM {user_info_data} uid3
                        JOIN {user_info_field} uif3 ON uid3.fieldid = uif3.id
                        WHERE uif3.shortname = :becsdeputyfieldshortname
                        AND (',' || uid3.data || ',' LIKE '%,' || :becsdeputyid || ',%')
                        AND (',' || uid.data || ',' LIKE '%,' || uid3.userid || ',%')
                    )
                    || ',%'
                )
            )";

            return "$waitforconfirmation AND $conflevel AND ($supervisorcond OR $deputycond)";
        }
    }

    /**
     * This returns the sql corresponding to the right settings.
     * When adding params, make sure the don't interfer.
     * Prefix them with bec (bookingextension_confirmation), eg bectuserid or becsuserid.
     * @param array $params
     *
     * @return string
     *
     */
    public function return_where_sql_mysql(array &$params): string {
        global $USER;

        $supervisorfieldshortname = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $hrids = explode(',', get_config('bookingextension_confirmation_supervisor', 'confirmation_supervisor_hrusers'));
        $ishr = in_array($USER->id, $hrids);

        // Params.
        $params['supervisorfieldshortname'] = $supervisorfieldshortname;
        $params['currentuserid'] = $USER->id;

        // Core condition.
        $waitforconfirmation = "CAST(JSON_UNQUOTE(JSON_EXTRACT(bo.json, '$.waitforconfirmation')) AS UNSIGNED) > 0";

        if ($ishr) {
            // HR should see 2, 3, 4.
            $conflevel = "CAST(JSON_UNQUOTE(JSON_EXTRACT(bo.json, '$.confirmationsupervisorenabled')) AS UNSIGNED) IN (2,3,4,5)";

            return "($waitforconfirmation AND $conflevel)";
        } else {
            // Supervisors should see 1, 2, 4 — but must be in the profile field.
            $conflevel = "CAST(JSON_UNQUOTE(JSON_EXTRACT(bo.json, '$.confirmationsupervisorenabled')) AS UNSIGNED) IN (1,2,4,5)";
            $supervisorcond = "EXISTS (
                SELECT 1
                FROM {user_info_data} uid
                JOIN {user_info_field} uif ON uid.fieldid = uif.id
                WHERE uif.shortname = :supervisorfieldshortname2
                AND uid.userid = u.id
                AND FIND_IN_SET(:currentuserid, uid.data) > 0
            )";
            $deputycond = "EXISTS (
                SELECT 1
                FROM {user_info_data} uid
                JOIN {user_info_field} uif ON uid.fieldid = uif.id
                WHERE uif.shortname = :supervisorfieldshortname
                AND uid.userid = u.id
                AND FIND_IN_SET(
                    (
                        SELECT uid3.userid
                        FROM mdl_user_info_data uid3
                        JOIN mdl_user_info_field uif3 ON uid3.fieldid = uif3.id
                        WHERE uif3.shortname = :becsdeputyfieldshortname
                        AND FIND_IN_SET(:becsdeputyid, uid3.data)
                        LIMIT 1
                    ),
                    uid.data) > 0
            )";

            return "($waitforconfirmation AND $conflevel AND ($supervisorcond OR $deputycond))";
        }
    }

    /**
     * Returns the number of required confirmations based on the booking option settings.
     *
     * @param int $optionid
     * @return int Number of confirmations needed (e.g., 1 or 2)
     */
    public static function get_required_confirmation_count(int $optionid): int {
        $bosettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (property_exists($bosettings->jsonobject, 'confirmationsupervisorenabled')) {
            $confirmationsupervisorenabled = (int) $bosettings->jsonobject->confirmationsupervisorenabled;
            switch ($confirmationsupervisorenabled) {
                case 1:
                case 3:
                case 5:
                    return 1;
                case 2:
                case 4:
                    return 2;
                default:
                    return 0;
            }
        }

        return 0;  // When the option 'no confirmation needed' is selected in booking option settings.
    }

    /**
     * Determines if user is HR.
     * @param mixed $approverid
     * @return bool
     */
    private static function is_hr($approverid): bool {
        // Check if user is HR. So we need to check bookig extension configuration.
        $hrids = explode(
            ',',
            get_config('bookingextension_confirmation_supervisor', 'confirmation_supervisor_hrusers')
        );

        return in_array($approverid, $hrids);
    }

    /**
     * Determines if approver is supervisor if given user.
     * @param mixed $approverid
     * @param mixed $userid
     * @return bool
     */
    private static function is_supervisor($approverid, $userid): bool {
        $supervisorfieldshortname = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $user = singleton_service::get_instance_of_user($userid, true);
        if (
            $user
            && isset($user->profile[$supervisorfieldshortname])
            && $user->profile[$supervisorfieldshortname] == $approverid
        ) {
            return true;
        }
        return false;
    }

    /**
     * Determines if appriver is deputy of the supervisor of the given user.
     * @param mixed $approverid
     * @param mixed $userid
     * @return bool
     */
    private static function is_deputy($approverid, $userid) {
        $supervisorfield = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $user = singleton_service::get_instance_of_user($userid, true);
        if ($user && !empty($user->profile[$supervisorfield])) {
            // Found the supervisor of the user. (user is owner of the answer).
            $svid = $user->profile[$supervisorfield]; // Supervisor ID.
            $sv = singleton_service::get_instance_of_user($svid, true); // Supervisor.

            if ($sv) {
                $deputies = self::get_deputies($sv);
                foreach ($deputies as $deputyid) {
                    if ($deputyid == $approverid) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Returns deputies of as an array of integers.
     * @param mixed $user
     * @return string[]
     */
    private static function get_deputies($user = null): array {
        $deputyfield = get_config('bookingextension_confirmation_supervisor', 'deputy');
        if ($user && $user->profile[$deputyfield]) {
            // Deputy Found.
            return explode(',', $user->profile[$deputyfield]);
        }
        return [];
    }
}
