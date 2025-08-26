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
 * Library of common module functions and constants.
 *
 * @package     bookingextension_confirmation_supervisor
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// phpcs:disable

// To add Option fields to the option form, register the ids here.
// Fields - Currently supported range: 321-329 and 501-504.


define('MOD_BOOKING_OPTION_FIELD_CONFIRMATION_SUPERVISOR', 393); // 392 to 399 for confirmation workflows.
$options = [
    0 => get_string('noconfirmationneeded', 'bookingextension_confirmation_supervisor'),
    1 => get_string('confirmbysupervisor', 'bookingextension_confirmation_supervisor'),
    2 => get_string('confirmbyhrsupervisor', 'bookingextension_confirmation_supervisor'),
    3 => get_string('confirmbyhr', 'bookingextension_confirmation_supervisor'),
    4 => get_string('confirmbysupervisorhr', 'bookingextension_confirmation_supervisor'),
    5 => get_string('confirmbysupervisororhr', 'bookingextension_confirmation_supervisor'),
];
define('CONFIRMATION_ORDER_OPTIONS', $options);

// Headers.
// define('MOD_BOOKING_HEADER_SKELETON', 'confirmation_supervisorheader');
// phpcs:enable
