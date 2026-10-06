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

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\privacy_anonymizer;

/**
 * The requester in a person field, for every skill (#2569).
 *
 * The constructor names the requester with the requester's own anonymized identity (the current_user token); the
 * engine replaces that identity in every person field with {@see self::MARKER}, and every person resolver reads the
 * marker as the acting user. Unlike dropping the field (#2246), this works for person fields whose empty value
 * means somebody else or nobody (a trainer, a restriction list) and for required person fields. Equality on engine
 * state only: the token map, the user record and the field declaration - no word lists.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class requester_reference {
    /** @var string The value of a person field that means the acting user. Skills outside the engine use the literal. */
    public const MARKER = '__current_user__';

    /**
     * Whether a person reference is the marker for the acting user.
     *
     * @param mixed $value
     * @return bool
     */
    public static function is_marker($value): bool {
        return is_scalar($value) && trim((string)$value) === self::MARKER;
    }

    /**
     * Whether an input field of a skill resolves persons (#2363, F23).
     *
     * A field qualifies by the engine-wide naming convention ({@see privacy_anonymizer::is_person_reference_field()})
     * or because the skill declares it through the duck-typed get_person_reference_fields(). One definition for the
     * person-reference gate and for the requester mapping. No skill-name lists in the engine.
     *
     * @param skill_interface|null $skill
     * @param string $field
     * @param privacy_anonymizer $anonymizer
     * @return bool
     */
    public static function is_person_field(?skill_interface $skill, string $field, privacy_anonymizer $anonymizer): bool {
        $normalized = \core_text::strtolower(trim($field));
        if ($normalized === '') {
            return false;
        }
        if ($anonymizer->is_person_reference_field($normalized)) {
            return true;
        }
        if ($skill === null || !method_exists($skill, 'get_person_reference_fields')) {
            return false;
        }
        foreach ((array)$skill->get_person_reference_fields() as $declared) {
            if (\core_text::strtolower(trim((string)$declared)) === $normalized) {
                return true;
            }
        }
        return false;
    }

    /**
     * The identities that name the requester in a person field, lower-cased: the anonymized tokens the model sees
     * and, defensively, the clear-text id, username, e-mail and full name.
     *
     * @param privacy_anonymizer $anonymizer
     * @param \bookingextension_agent\local\wizard\conversation_store $store
     * @param int $threadid
     * @param int $userid Acting user.
     * @return string[]
     */
    public static function identities(
        privacy_anonymizer $anonymizer,
        \bookingextension_agent\local\wizard\conversation_store $store,
        int $threadid,
        int $userid
    ): array {
        if ($userid <= 0) {
            return [];
        }
        $user = \core_user::get_user($userid, '*', IGNORE_MISSING);
        if (!$user) {
            return [];
        }
        $identities = $threadid > 0
            ? runtime_context_block_builder::current_user_identity_tokens($anonymizer, $threadid, $store)
            : [];
        foreach ([(string)$userid, (string)($user->username ?? ''), (string)($user->email ?? ''), fullname($user)] as $id) {
            if (trim($id) !== '') {
                $identities[] = trim($id);
            }
        }
        return array_values(array_unique(array_map(static fn(string $v): string => \core_text::strtolower($v), $identities)));
    }

    /**
     * A person field's value with every reference to the requester replaced by the marker.
     *
     * A list (array) is mapped per entry, a comma-separated string per part - the delimiter the person-list fields
     * declare. An id stays an id: it resolves to the requester without help, so only names, tokens and addresses
     * are replaced.
     *
     * @param mixed $value
     * @param string[] $identities From {@see self::identities()}.
     * @param string $userid The acting user's id as string.
     * @return mixed
     */
    public static function map_value($value, array $identities, string $userid) {
        if (is_array($value)) {
            return array_map(static fn($entry) => self::map_value($entry, $identities, $userid), $value);
        }
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }
        $parts = explode(',', $value);
        $changed = false;
        foreach ($parts as $i => $part) {
            $normalized = \core_text::strtolower(trim($part));
            if ($normalized !== '' && $normalized !== $userid && in_array($normalized, $identities, true)) {
                $parts[$i] = (count($parts) > 1 && str_starts_with($part, ' ') ? ' ' : '') . self::MARKER;
                $changed = true;
            }
        }
        return $changed ? implode(',', $parts) : $value;
    }
}
