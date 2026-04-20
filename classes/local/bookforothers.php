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

namespace bookingextension_confirmation_supervisor\local;

use mod_booking\local\interfaces\bookingextension\bookforothers_interface;

/**
 * Class bookforothers
 *
 * @package    bookingextension_confirmation_supervisor
 * @copyright  2026 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookforothers implements bookforothers_interface {
    public static function has_capability_to_book_for_others(int $optionid, int $agentid, int $userid): array {
        if (has_capability('bookmyteam', \context_module::instance(get_course_module_id_by_optionid($optionid)), $agentid)) {
            return [true, '', false];
        } else {
            return [false, get_string('notallowedtobookforothers', 'mod_booking'), false];
        }
    }
}
