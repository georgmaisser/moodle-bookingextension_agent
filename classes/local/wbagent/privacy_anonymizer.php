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
 * Privacy anonymization helper for LLM-bound text.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

use core_text;

/**
 * Handles pre-LLM anonymization and pre-execution de-anonymization.
 */
class privacy_anonymizer {
    /** @var string Privacy mode disabled. */
    private const MODE_OFF = 'off';
    /** @var string Privacy mode with only backend/system anonymization. */
    private const MODE_SOFT = 'soft';
    /** @var string Privacy mode with strict user-message anonymization. */
    private const MODE_STRICT = 'strict';

    /** @var string Thread metadata key for token map. */
    private const TOKEN_MAP_METADATA_KEY = 'privacy_anon_map';
    /** @var string Cache key for distinct-name index. */
    private const NAME_INDEX_CACHE_KEY = 'distinct_user_names_v1';
    /** @var string Cache key for user-linked name matching index. */
    private const NAME_MATCH_INDEX_CACHE_KEY = 'user_name_match_index_v1';
    /** @var array<int,string> Common words that must never be treated as person names. */
    private const NAME_STOPWORDS = [
        'von', 'bei', 'mit', 'und', 'oder', 'der', 'die', 'das', 'dem', 'den', 'des',
        'ein', 'eine', 'einer', 'einem', 'einen', 'ich', 'du', 'er', 'sie', 'wir', 'ihr',
        'sein', 'ihre', 'ihren', 'soll', 'sollen', 'bitte', 'hier', 'dort', 'im', 'in',
        'am', 'an', 'auf', 'zu', 'zur', 'zum', 'for', 'and', 'or', 'the', 'a', 'an',
        'to', 'with', 'by', 'is', 'are', 'be',
        // Generic nouns frequently found in result summaries must never be treated as names.
        'user', 'users', 'benutzer', 'teilnehmer', 'teilnehmende',
    ];
    /** @var array<int,string> Fields that should always resolve to original literal text for SQL updates. */
    private const SQL_TEXT_FIELDS = ['text', 'description', 'optionquery'];
    /** @var array<int,string> Fields that represent user references and should prefer shorter observed variants. */
    private const USER_REFERENCE_FIELDS = ['userquery', 'teacherquery', 'targetuserquery'];
    /** @var array<int,string> Structured person fields that must be anonymized independently. */
    private const PERSON_IDENTITY_FIELDS = ['firstname', 'lastname', 'email'];

    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Return current privacy mode.
     *
     * @return string
     */
    public function get_mode(): string {
        $mode = (string)(get_config('bookingextension_agent', 'aiprivacymode') ?: self::MODE_OFF);
        if (!in_array($mode, [self::MODE_OFF, self::MODE_SOFT, self::MODE_STRICT], true)) {
            return self::MODE_OFF;
        }
        return $mode;
    }

    /**
     * Return true if the given string value looks like an ANON token.
     *
     * Tasks call this static helper to skip semantic validation on values that
     * are anonymized placeholders.  No infrastructure is required — the check
     * is a pure string test.
     *
     * @param  string $value
     * @return bool
     */
    public static function looks_like_anon_token(string $value): bool {
        return (bool)preg_match('/\bANON_USER_\d+(?:_[a-z]+)?\b/', $value);
    }

    /**
     * Whether strict pre-LLM anonymization of user input is required.
     *
     * @return bool
     */
    public function should_anonymize_user_input(): bool {
        return $this->get_mode() === self::MODE_STRICT;
    }

    /**
     * Whether backend data sent to the LLM must be anonymized.
     *
     * @return bool
     */
    public function should_anonymize_llm_backend_data(): bool {
        return $this->get_mode() !== self::MODE_OFF;
    }

    /**
     * Precheck and anonymize user text before it is persisted/sent to LLM.
     *
     * @param int $threadid
     * @param string $message
     * @return array
     */
    public function precheck_user_message(int $threadid, string $message): array {
        $start = microtime(true);
        $sanitized = $message;
        $emailcount = 0;
        $namecount = 0;

        $mode = $this->get_mode();
        if ($mode === self::MODE_OFF) {
            return [
                'sanitizedmessage' => $message,
                'anonymizedcount' => 0,
                'anonymizedemails' => 0,
                'anonymizednames' => 0,
                'elapsedms' => (int)round((microtime(true) - $start) * 1000),
                'blocked' => false,
            ];
        }

        $tokenmap = $this->get_token_map($threadid);

        // In privacy mode, names must never be sent to the LLM in clear text.
        if ($this->should_anonymize_user_input()) {
            [$sanitized, $emailcount] = $this->anonymize_emails($sanitized, $tokenmap);
        }
        [$sanitized, $namecount] = $this->anonymize_names($sanitized, $tokenmap);

        $this->set_token_map($threadid, $tokenmap);

        return [
            'sanitizedmessage' => $sanitized,
            'anonymizedcount' => $emailcount + $namecount,
            'anonymizedemails' => $emailcount,
            'anonymizednames' => $namecount,
            'elapsedms' => (int)round((microtime(true) - $start) * 1000),
            'blocked' => false,
        ];
    }

    /**
     * Replace ANON_USER tokens in command input recursively with original values.
     *
     * @param int $threadid
     * @param array $input
     * @return array
     */
    public function deanonymize_command_input(int $threadid, array $input): array {
        if ($this->get_mode() === self::MODE_OFF) {
            return $input;
        }

        $tokenmap = $this->get_token_map($threadid);
        if (empty($tokenmap['entries']) || !is_array($tokenmap['entries'])) {
            return $input;
        }

        return $this->deanonymize_recursive($input, $tokenmap['entries'], '');
    }

    /**
     * De-anonymize command input using the active user thread in a booking context.
     *
     * Useful during validation where only cmid/userid is available.
     *
     * @param int $cmid
     * @param int $userid
     * @param array $input
     * @return array
     */
    public function deanonymize_command_input_for_active_user(int $cmid, int $userid, array $input): array {
        if ($this->get_mode() === self::MODE_OFF || $cmid <= 0 || $userid <= 0) {
            return $input;
        }

        $thread = $this->store->get_active_thread($userid, $cmid);
        if (!$thread || empty($thread->id)) {
            return $input;
        }

        return $this->deanonymize_command_input((int)$thread->id, $input);
    }

    /**
     * De-mask assistant text for user display only (no persistence side effects).
     *
     * @param int $threadid
     * @param string $message
     * @return array
     */
    public function deanonymize_message_for_display(int $threadid, string $message): array {
        if ($message === '' || $this->get_mode() === self::MODE_OFF) {
            return [
                'message' => $message,
                'replacedcount' => 0,
            ];
        }

        $tokenmap = $this->get_token_map($threadid);
        $entries = $tokenmap['entries'] ?? [];
        if (!is_array($entries) || empty($entries)) {
            return [
                'message' => $message,
                'replacedcount' => 0,
            ];
        }

        $replacedcount = 0;
        $displaymessage = preg_replace_callback(
            '/\bANON_USER_\d+(?:_[a-z]+)?\b/',
            static function (array $m) use ($entries, &$replacedcount): string {
                $token = (string)$m[0];
                $entry = $entries[$token] ?? null;
                if (!is_array($entry)) {
                    return $token;
                }

                $replacedcount++;
                $original = (string)($entry['original'] ?? '');
                $value = (string)($entry['value'] ?? '');
                $replacement = $original !== '' ? $original : ($value !== '' ? $value : $token);
                $matchtype = (string)($entry['type'] ?? '');
                if (in_array($matchtype, ['firstname', 'lastname', 'name', 'both'], true)) {
                    return $replacement . ' 👤';
                }
                if ($matchtype === 'email') {
                    return $replacement . '👤';
                }

                return $replacement;
            },
            $message
        );

        // For full names split across multiple anonymized tokens, keep only one trailing marker.
        $displaymessage = preg_replace(
            '/\s+👤(?=\s+\p{Lu}[\p{L}\p{M}\-]+\s+👤)/u',
            '',
            (string)$displaymessage
        );

        return [
            'message' => (string)$displaymessage,
            'replacedcount' => $replacedcount,
        ];
    }

    /**
     * Recursively anonymize arbitrary payload data before it is sent to the LLM.
     *
     * @param int $threadid
     * @param mixed $value
     * @return mixed
     */
    public function anonymize_value_for_llm(int $threadid, $value) {
        if (!$this->should_anonymize_llm_backend_data()) {
            return $value;
        }

        $tokenmap = $this->get_token_map($threadid);
        $sanitized = $this->anonymize_value_recursive($value, $tokenmap);
        $this->set_token_map($threadid, $tokenmap);

        return $sanitized;
    }

    /**
     * Recursively de-anonymize all string values in input payload.
     *
     * @param mixed $value
     * @param array $entries
     * @param string $fieldkey
     * @return mixed
     */
    private function deanonymize_recursive($value, array $entries, string $fieldkey) {
        if (is_string($value)) {
            return preg_replace_callback('/\bANON_USER_\d+(?:_[a-z]+)?\b/', function (array $m) use ($entries, $fieldkey): string {
                $token = $m[0];
                $entry = $this->resolve_token_entry($entries, $token);
                if (!is_array($entry)) {
                    return $token;
                }
                return $this->resolve_entry_for_field($entry, $fieldkey, $token);
            }, $value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $childfield = is_string($key) ? $key : $fieldkey;
            $value[$key] = $this->deanonymize_recursive($item, $entries, $childfield);
        }

        return $value;
    }

    /**
     * Resolve a token entry for exact and base-token variants.
     *
     * Planner output can contain ANON_USER_<n> while the token map contains only
     * ANON_USER_<n>_firstname / _lastname / _email / _both variants.
     *
     * @param array<string,mixed> $entries
     * @param string $token
     * @return array<string,mixed>|null
     */
    private function resolve_token_entry(array $entries, string $token): ?array {
        $entry = $entries[$token] ?? null;
        if (is_array($entry)) {
            return $entry;
        }

        $base = $this->extract_base_token_from_anon_token($token);
        if ($base === '') {
            return null;
        }

        foreach (['both', 'email', 'firstname', 'lastname'] as $suffix) {
            $candidate = $entries[$base . '_' . $suffix] ?? null;
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Recursively anonymize string values in an arbitrary payload.
     *
     * @param mixed $value
     * @param array $tokenmap
     * @return mixed
     */
    private function anonymize_value_recursive($value, array &$tokenmap, string $fieldkey = '') {
        if (is_string($value)) {
            return $this->anonymize_string_for_llm($value, $tokenmap, $fieldkey);
        }

        if (!is_array($value)) {
            return $value;
        }

        if ($this->array_contains_person_identity_fields($value)) {
            $value = $this->anonymize_person_identity_field_group($value, $tokenmap);
        }

        foreach ($value as $key => $item) {
            $childfield = is_string($key) ? $key : $fieldkey;
            $value[$key] = $this->anonymize_value_recursive($item, $tokenmap, $childfield);
        }

        return $value;
    }

    /**
     * Anonymize a free-form string for backend LLM use.
     *
     * @param string $message
     * @param array $tokenmap
     * @return string
     */
    private function anonymize_string_for_llm(string $message, array &$tokenmap, string $fieldkey = ''): string {
        if ($message === '') {
            return $message;
        }

        $normalizedfield = core_text::strtolower(trim($fieldkey));

        if (in_array($normalizedfield, self::PERSON_IDENTITY_FIELDS, true)) {
            $direct = $this->anonymize_person_field_value($normalizedfield, $message, $tokenmap);
            if ($direct !== null) {
                return $direct;
            }
        }

        // Field-labeled summaries (firstname=..., lastname=..., email=...) must keep
        // each identity field separate, otherwise one token can collapse all values.
        [$message] = $this->anonymize_labeled_user_fields($message, $tokenmap);

        [$message] = $this->anonymize_emails($message, $tokenmap);
        [$message] = $this->anonymize_names($message, $tokenmap);

        return $message;
    }

    /**
     * Anonymize labeled user fields in text while preserving field semantics.
     *
     * Example pattern: firstname=Max, lastname=Mustermann, email=max@example.com
     *
     * @param string $message
     * @param array $tokenmap
     * @return array{0:string,1:int}
     */
    private function anonymize_labeled_user_fields(string $message, array &$tokenmap): array {
        $count = 0;
        $sanitized = $message;

        $sanitized = preg_replace_callback(
            '/\b(firstname|lastname)\s*=\s*([^,|\.\n]+)/iu',
            function (array $match) use (&$tokenmap, &$count): string {
                $field = core_text::strtolower(trim((string)($match[1] ?? '')));
                $rawvalue = trim((string)($match[2] ?? ''));
                if ($rawvalue === '' || $rawvalue === '-' || self::looks_like_anon_token($rawvalue)) {
                    return (string)$match[0];
                }

                $token = $this->anonymize_person_field_value($field, $rawvalue, $tokenmap);
                if ($token === null) {
                    return (string)$match[0];
                }

                $count++;
                return $field . '=' . $token;
            },
            $sanitized
        );

        $sanitized = preg_replace_callback(
            '/\b(email)\s*=\s*([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/iu',
            function (array $match) use (&$tokenmap, &$count): string {
                $field = 'email';
                $rawvalue = trim((string)($match[2] ?? ''));
                if ($rawvalue === '' || self::looks_like_anon_token($rawvalue)) {
                    return (string)$match[0];
                }

                $token = $this->anonymize_person_field_value($field, $rawvalue, $tokenmap);
                if ($token === null) {
                    return (string)$match[0];
                }

                $count++;
                return $field . '=' . $token;
            },
            $sanitized
        );

        return [(string)$sanitized, $count];
    }

    /**
     * Anonymize one identity field value with field-specific token semantics.
     *
     * @param string $field firstname|lastname|email
     * @param string $value
     * @param array $tokenmap
     * @return string|null
     */
    private function anonymize_person_field_value(string $field, string $value, array &$tokenmap): ?string {
        $normalizedfield = core_text::strtolower(trim($field));
        $trimmedvalue = trim($value);
        if ($trimmedvalue === '') {
            return null;
        }
        if (self::looks_like_anon_token($trimmedvalue)) {
            return $trimmedvalue;
        }

        if ($normalizedfield === 'email') {
            $identity = $this->resolve_identity_from_email($trimmedvalue);
            return $this->get_or_create_token(
                $tokenmap,
                (string)($identity['identitykey'] ?? ('email:' . core_text::strtolower($trimmedvalue))),
                'email',
                $trimmedvalue,
                $trimmedvalue,
                (array)($identity['variants'] ?? ['email' => $trimmedvalue])
            );
        }

        if (!in_array($normalizedfield, ['firstname', 'lastname'], true)) {
            return null;
        }

        $normalizedname = $this->normalize_name($trimmedvalue);
        if ($normalizedname === '') {
            return null;
        }

        $matchindex = $this->get_user_name_match_index();
        $candidateuserids = [];
        if ($normalizedfield === 'firstname') {
            $candidateuserids = array_keys((array)(($matchindex['firstusers'] ?? [])[$normalizedname] ?? []));
        } else {
            $candidateuserids = array_keys((array)(($matchindex['lastusers'] ?? [])[$normalizedname] ?? []));
        }

        $identity = $this->resolve_identity_from_user_ids($candidateuserids, [$normalizedfield => $trimmedvalue]);

        return $this->get_or_create_token(
            $tokenmap,
            (string)($identity['identitykey'] ?? ($normalizedfield . ':' . $normalizedname)),
            $normalizedfield,
            $trimmedvalue,
            $trimmedvalue,
            (array)($identity['variants'] ?? [$normalizedfield => $trimmedvalue])
        );
    }

    /**
     * Replace email-like values with ANON tokens.
     *
     * @param string $message
     * @param array $tokenmap
     * @return array{0:string,1:int}
     */
    private function anonymize_emails(string $message, array &$tokenmap): array {
        $count = 0;
        $sanitized = preg_replace_callback(
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
            function (array $match) use (&$tokenmap, &$count): string {
                $email = (string)$match[0];
                $identity = $this->resolve_identity_from_email($email);
                $token = $this->get_or_create_token(
                    $tokenmap,
                    (string)($identity['identitykey'] ?? ('email:' . core_text::strtolower($email))),
                    'email',
                    $email,
                    $email,
                    (array)($identity['variants'] ?? ['email' => $email])
                );
                $count++;
                return $token;
            },
            $message
        );

        return [(string)$sanitized, $count];
    }

    /**
     * Replace distinct known first/last names with ANON tokens.
     *
     * Recognizes firstname-lastname pairs as single entities to avoid creating
     * multiple tokens for a single person reference.
     *
     * @param string $message
     * @param array $tokenmap
     * @return array{0:string,1:int}
     */
    private function anonymize_names(string $message, array &$tokenmap): array {
        $matchindex = $this->get_user_name_match_index();
        $nameindex = is_array($matchindex['types'] ?? null) ? (array)$matchindex['types'] : [];
        $firstusers = is_array($matchindex['firstusers'] ?? null) ? (array)$matchindex['firstusers'] : [];
        $lastusers = is_array($matchindex['lastusers'] ?? null) ? (array)$matchindex['lastusers'] : [];
        $fullusers = is_array($matchindex['fullusers'] ?? null) ? (array)$matchindex['fullusers'] : [];
        $emailspans = $this->find_email_spans($message);

        if (empty($nameindex)) {
            return [$message, 0];
        }

        $wordmatches = [];
        preg_match_all('/\b[\p{L}][\p{L}\p{M}\-]{2,}\b/u', $message, $wordmatches, PREG_OFFSET_CAPTURE);
        $words = $wordmatches[0] ?? [];
        if (empty($words)) {
            return [$message, 0];
        }

        $count = 0;
        $replaceword = [];
        $skipword = [];

        // Pass 1: full-name check always first.
        for ($i = 0; $i < count($words) - 1; $i++) {
            if (!empty($skipword[$i]) || !empty($skipword[$i + 1])) {
                continue;
            }

            $firsttoken = (string)$words[$i][0];
            $lasttoken = (string)$words[$i + 1][0];
            $firststart = (int)$words[$i][1];
            $secondstart = (int)$words[$i + 1][1];
            if (
                $this->offset_overlaps_email_span($firststart, $emailspans)
                || $this->offset_overlaps_email_span($secondstart, $emailspans)
            ) {
                continue;
            }

            $firstnorm = $this->normalize_name($firsttoken);
            $lastnorm = $this->normalize_name($lasttoken);
            if (
                $firstnorm === '' || $lastnorm === ''
                || in_array($firstnorm, self::NAME_STOPWORDS, true)
                || in_array($lastnorm, self::NAME_STOPWORDS, true)
            ) {
                continue;
            }

            $firstend = $firststart + strlen($firsttoken);
            $between = substr($message, $firstend, $secondstart - $firstend);
            if (!preg_match('/^\s+$/u', (string)$between)) {
                continue;
            }

            $fullkey = $firstnorm . ' ' . $lastnorm;
            $fullmatchusers = $fullusers[$fullkey] ?? [];
            if (is_array($fullmatchusers) && !empty($fullmatchusers)) {
                $fullname = $firsttoken . $between . $lasttoken;
                $identity = $this->resolve_identity_from_user_ids(array_keys($fullmatchusers), [
                    'both' => $fullname,
                    'firstname' => $firsttoken,
                    'lastname' => $lasttoken,
                ]);
                $replaceword[$i] = $this->get_or_create_token(
                    $tokenmap,
                    (string)($identity['identitykey'] ?? ('name:' . $fullkey)),
                    'both',
                    $fullname,
                    $fullname,
                    (array)($identity['variants'] ?? [
                        'both' => $fullname,
                        'firstname' => $firsttoken,
                        'lastname' => $lasttoken,
                    ])
                );
                $replaceword[$i + 1] = '';
                $skipword[$i + 1] = true;
                $count++;
                continue;
            }

            // Only allow split firstname/lastname masking if they cannot belong to the same user.
            $firstids = is_array($firstusers[$firstnorm] ?? null) ? (array)$firstusers[$firstnorm] : [];
            $lastids = is_array($lastusers[$lastnorm] ?? null) ? (array)$lastusers[$lastnorm] : [];
            if ($this->user_sets_intersect($firstids, $lastids)) {
                $skipword[$i] = true;
                $skipword[$i + 1] = true;
            }
        }

        // Pass 2: single-token fallback only where pass 1 found no valid full-name pair.
        foreach ($words as $idx => $entry) {
            if (array_key_exists($idx, $replaceword) || !empty($skipword[$idx])) {
                continue;
            }

            $tokenvalue = (string)$entry[0];
            $tokenstart = (int)$entry[1];
            if ($this->offset_overlaps_email_span($tokenstart, $emailspans)) {
                continue;
            }

            $normalized = $this->normalize_name($tokenvalue);
            if ($normalized === '' || in_array($normalized, self::NAME_STOPWORDS, true)) {
                continue;
            }

            $matchtype = (string)($nameindex[$normalized] ?? '');
            if ($matchtype === '') {
                continue;
            }
            if ($matchtype === 'both') {
                $matchtype = 'firstname';
            }

            $candidateuserids = [];
            if ($matchtype === 'firstname') {
                $candidateuserids = array_keys((array)($firstusers[$normalized] ?? []));
            } else if ($matchtype === 'lastname') {
                $candidateuserids = array_keys((array)($lastusers[$normalized] ?? []));
            }
            $identity = $this->resolve_identity_from_user_ids($candidateuserids, [
                $matchtype => $tokenvalue,
            ]);
            $replaceword[$idx] = $this->get_or_create_token(
                $tokenmap,
                (string)($identity['identitykey'] ?? ($matchtype . ':' . $normalized)),
                $matchtype,
                $tokenvalue,
                $tokenvalue,
                (array)($identity['variants'] ?? [$matchtype => $tokenvalue])
            );
            $count++;
        }

        $sanitized = '';
        $cursor = 0;
        foreach ($words as $idx => $entry) {
            $tokenvalue = (string)$entry[0];
            $start = (int)$entry[1];
            $end = $start + strlen($tokenvalue);
            $sanitized .= substr($message, $cursor, $start - $cursor);
            if (array_key_exists($idx, $replaceword)) {
                $sanitized .= (string)$replaceword[$idx];
            } else {
                $sanitized .= $tokenvalue;
            }
            $cursor = $end;
        }
        $sanitized .= substr($message, $cursor);

        return [$sanitized, $count];
    }

    /**
     * Find byte-offset spans of email addresses in message text.
     *
     * @param string $message
     * @return array<int,array{start:int,end:int}>
     */
    private function find_email_spans(string $message): array {
        $spans = [];
        $matches = [];
        preg_match_all(
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
            $message,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ((array)($matches[0] ?? []) as $match) {
            if (!is_array($match) || count($match) < 2) {
                continue;
            }

            $email = (string)$match[0];
            $start = (int)$match[1];
            $spans[] = [
                'start' => $start,
                'end' => $start + strlen($email),
            ];
        }

        return $spans;
    }

    /**
     * Return true when offset belongs to an email span.
     *
     * @param int $offset
     * @param array<int,array{start:int,end:int}> $spans
     * @return bool
     */
    private function offset_overlaps_email_span(int $offset, array $spans): bool {
        foreach ($spans as $span) {
            $start = (int)($span['start'] ?? 0);
            $end = (int)($span['end'] ?? 0);
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build name matching index with user-id links for full/split name decisions.
     *
     * @return array
     */
    private function get_user_name_match_index(): array {
        global $DB;

        $cache = \cache::make('mod_booking', 'aiprivacynames');
        $cached = $cache->get(self::NAME_MATCH_INDEX_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $types = [];
        $firstusers = [];
        $lastusers = [];
        $fullusers = [];

        $users = $DB->get_records_select(
            'user',
            'deleted = 0 AND suspended = 0',
            null,
            '',
            'id,firstname,lastname'
        );

        foreach ($users as $user) {
            $userid = (int)($user->id ?? 0);
            if ($userid <= 0) {
                continue;
            }

            $first = $this->normalize_name((string)($user->firstname ?? ''));
            $last = $this->normalize_name((string)($user->lastname ?? ''));

            if ($first !== '') {
                $types[$first] = (($types[$first] ?? '') === 'lastname') ? 'both' : 'firstname';
                if (!isset($firstusers[$first]) || !is_array($firstusers[$first])) {
                    $firstusers[$first] = [];
                }
                $firstusers[$first][$userid] = true;
            }

            if ($last !== '') {
                $types[$last] = (($types[$last] ?? '') === 'firstname') ? 'both' : 'lastname';
                if (!isset($lastusers[$last]) || !is_array($lastusers[$last])) {
                    $lastusers[$last] = [];
                }
                $lastusers[$last][$userid] = true;
            }

            if ($first !== '' && $last !== '') {
                $fullkey = $first . ' ' . $last;
                if (!isset($fullusers[$fullkey]) || !is_array($fullusers[$fullkey])) {
                    $fullusers[$fullkey] = [];
                }
                $fullusers[$fullkey][$userid] = true;
            }
        }

        $index = [
            'types' => $types,
            'firstusers' => $firstusers,
            'lastusers' => $lastusers,
            'fullusers' => $fullusers,
        ];

        $cache->set(self::NAME_MATCH_INDEX_CACHE_KEY, $index);
        return $index;
    }

    /**
     * Determine whether two user-id maps overlap.
     *
     * @param array $left
     * @param array $right
     * @return bool
     */
    private function user_sets_intersect(array $left, array $right): bool {
        if (empty($left) || empty($right)) {
            return false;
        }

        foreach ($left as $userid => $value) {
            if (isset($right[$userid])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load distinct name index from cache or database.
     *
     * @return array
     */
    private function get_distinct_name_index(): array {
        global $DB;

        $cache = \cache::make('mod_booking', 'aiprivacynames');
        $cached = $cache->get(self::NAME_INDEX_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $index = [];

        $firstnames = $DB->get_fieldset_select('user', 'firstname', "deleted = 0 AND suspended = 0 AND firstname <> ''");
        $lastnames = $DB->get_fieldset_select('user', 'lastname', "deleted = 0 AND suspended = 0 AND lastname <> ''");

        foreach ($firstnames as $name) {
            $normalized = $this->normalize_name((string)$name);
            if ($normalized === '') {
                continue;
            }
            $index[$normalized] = 'firstname';
        }

        foreach ($lastnames as $name) {
            $normalized = $this->normalize_name((string)$name);
            if ($normalized === '') {
                continue;
            }

            if (($index[$normalized] ?? '') === 'firstname') {
                $index[$normalized] = 'both';
                continue;
            }

            $index[$normalized] = 'lastname';
        }

        $cache->set(self::NAME_INDEX_CACHE_KEY, $index);
        return $index;
    }

    /**
     * Normalize a candidate name for index/matching.
     *
     * @param string $name
     * @return string
     */
    private function normalize_name(string $name): string {
        $name = core_text::strtolower(trim($name));
        if ($name === '') {
            return '';
        }
        if (!preg_match('/^[\p{L}][\p{L}\p{M}\-]{2,}$/u', $name)) {
            return '';
        }

        return $name;
    }

    /**
     * Load or initialize the thread token map.
     *
     * @param int $threadid
     * @return array
     */
    private function get_token_map(int $threadid): array {
        $map = $this->store->get_thread_metadata_value($threadid, self::TOKEN_MAP_METADATA_KEY);
        if (!is_array($map)) {
            return ['nextid' => 1, 'entries' => []];
        }

        $nextid = (int)($map['nextid'] ?? 1);
        $entries = $map['entries'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }

        return [
            'nextid' => max(1, $nextid),
            'entries' => $entries,
        ];
    }

    /**
     * Persist token map on thread metadata.
     *
     * @param int $threadid
     * @param array $map
     * @return void
     */
    private function set_token_map(int $threadid, array $map): void {
        $this->store->set_thread_metadata_value($threadid, self::TOKEN_MAP_METADATA_KEY, $map);
    }

    /**
     * Return existing token for value or create a new token entry.
     *
     * @param array $map
     * @param string $type
     * @param string $value
     * @param string $original
     * @return string
     */
    private function get_or_create_token(
        array &$map,
        string $identitykey,
        string $type,
        string $value,
        string $original,
        array $variants = []
    ): string {
        $entries = $map['entries'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }

        $scopedidentitykey = $identitykey;
        $requiresfieldsuffixtoken = in_array($type, ['firstname', 'lastname', 'email', 'both'], true);
        $basetoken = '';

        if ($scopedidentitykey !== '') {
            foreach ($entries as $token => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if ((string)($entry['identitykey'] ?? '') !== $scopedidentitykey) {
                    continue;
                }

                $basetoken = $this->extract_base_token_from_anon_token((string)$token);
                if ($basetoken !== '') {
                    break;
                }
            }
        }

        if ($requiresfieldsuffixtoken) {
            if ($basetoken === '') {
                $nextid = max(1, (int)($map['nextid'] ?? 1));
                $basetoken = 'ANON_USER_' . $nextid;
                $map['nextid'] = $nextid + 1;
            }

            $targettoken = $this->build_field_token_from_base($basetoken, $type);
            if ($targettoken !== '') {
                $targetentry = is_array($entries[$targettoken] ?? null) ? (array)$entries[$targettoken] : [];
                $entries[$targettoken] = [
                    'identitykey' => $scopedidentitykey,
                    'type' => $type,
                    'value' => $value,
                    'original' => $original,
                    'variants' => $this->merge_identity_variants((array)($targetentry['variants'] ?? []), $variants),
                ];
                $map['entries'] = $entries;
                return $targettoken;
            }
        }

        foreach ($entries as $token => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ((string)($entry['identitykey'] ?? '') === $scopedidentitykey && $scopedidentitykey !== '') {
                $entry['type'] = $type;
                $entry['value'] = $value;
                $entry['original'] = $original;
                $entry['variants'] = $this->merge_identity_variants((array)($entry['variants'] ?? []), $variants);
                $entries[$token] = $entry;
                $map['entries'] = $entries;
                return (string)$token;
            }

            if (
                $identitykey === ''
                && (string)($entry['type'] ?? '') === $type
                && (string)($entry['value'] ?? '') === $value
                && (string)($entry['original'] ?? '') === $original
            ) {
                return (string)$token;
            }
        }

        $nextid = max(1, (int)($map['nextid'] ?? 1));
        $token = 'ANON_USER_' . $nextid;
        $entries[$token] = [
            'identitykey' => $scopedidentitykey,
            'type' => $type,
            'value' => $value,
            'original' => $original,
            'variants' => $this->merge_identity_variants([], $variants),
        ];
        $map['entries'] = $entries;
        $map['nextid'] = $nextid + 1;

        return $token;
    }

    /**
     * Scope identity keys by field type to avoid cross-field token collisions.
     *
     * @param string $identitykey
     * @param string $type
     * @return string
     */
    private function scope_identity_key_for_type(string $identitykey, string $type): string {
        if ($identitykey === '') {
            return '';
        }

        return $identitykey;
    }

    /**
     * Build a field-specific token from a base ANON token.
     *
     * @param string $basetoken
     * @param string $type
     * @return string
     */
    private function build_field_token_from_base(string $basetoken, string $type): string {
        $normalizedtype = core_text::strtolower(trim($type));
        if (!in_array($normalizedtype, ['firstname', 'lastname', 'email', 'both'], true)) {
            return '';
        }

        $normalizedbase = $this->extract_base_token_from_anon_token($basetoken);
        if ($normalizedbase === '') {
            return '';
        }

        return $normalizedbase . '_' . $normalizedtype;
    }

    /**
     * Extract the ANON_USER_<id> base token from any supported token variant.
     *
     * @param string $token
     * @return string
     */
    private function extract_base_token_from_anon_token(string $token): string {
        if (!preg_match('/^(ANON_USER_\d+)(?:_[a-z]+)?$/', $token, $match)) {
            return '';
        }

        return (string)($match[1] ?? '');
    }

    /**
     * Resolve token entry value based on destination field semantics.
     *
     * For SQL text fields (title/description/search query), always use original literal.
     *
     * @param array $entry
     * @param string $fieldkey
     * @param string $fallback
     * @return string
     */
    private function resolve_entry_for_field(array $entry, string $fieldkey, string $fallback): string {
        $original = (string)($entry['original'] ?? '');
        $value = (string)($entry['value'] ?? '');
        $matchtype = (string)($entry['type'] ?? '');
        $variants = is_array($entry['variants'] ?? null) ? (array)$entry['variants'] : [];
        $normalizedfield = core_text::strtolower(trim($fieldkey));

        if ($original === '' && $value === '') {
            return $fallback;
        }

        if (
            in_array($normalizedfield, self::SQL_TEXT_FIELDS, true)
            && in_array($matchtype, ['firstname', 'lastname', 'email'], true)
        ) {
            return $original !== '' ? $original : $value;
        }

        if ($this->is_user_reference_field($normalizedfield)) {
            foreach (['email', 'both', 'firstname', 'lastname'] as $variantkey) {
                $variant = trim((string)($variants[$variantkey] ?? ''));
                if ($variant !== '') {
                    return $variant;
                }
            }
        }

        return $value !== '' ? $value : ($original !== '' ? $original : $fallback);
    }

    /**
     * Resolve a stable identity from an e-mail address when possible.
     *
     * @param string $email
     * @return array
     */
    private function resolve_identity_from_email(string $email): array {
        global $DB;

        $normalizedemail = trim(core_text::strtolower($email));
        if ($normalizedemail === '') {
            return [
                'identitykey' => '',
                'variants' => ['email' => $email],
            ];
        }

        $user = $DB->get_record(
            'user',
            ['email' => $normalizedemail, 'deleted' => 0],
            'id,firstname,lastname,email',
            IGNORE_MISSING
        );
        if (!$user) {
            return [
                'identitykey' => 'email:' . $normalizedemail,
                'variants' => ['email' => $email],
            ];
        }

        return [
            'identitykey' => 'user:' . (int)$user->id,
            'variants' => $this->build_identity_variants_from_user_record($user, ['email' => $email]),
        ];
    }

    /**
     * Resolve a stable identity from a candidate user-id set.
     *
     * If the name fragment is ambiguous, keep a representation-based fallback identity.
     *
     * @param array $candidateuserids
     * @param array $observedvariants
     * @return array
     */
    private function resolve_identity_from_user_ids(array $candidateuserids, array $observedvariants = []): array {
        $candidateuserids = array_values(array_unique(array_map('intval', $candidateuserids)));
        if (count($candidateuserids) === 1 && $candidateuserids[0] > 0) {
            $user = $this->load_user_identity_record($candidateuserids[0]);
            if ($user) {
                return [
                    'identitykey' => 'user:' . $candidateuserids[0],
                    'variants' => $this->build_identity_variants_from_user_record($user, $observedvariants),
                ];
            }
        }

        $fallbackseed = json_encode($observedvariants);
        return [
            'identitykey' => 'literal:' . sha1((string)$fallbackseed),
            'variants' => $observedvariants,
        ];
    }

    /**
     * Load user identity fields for token enrichment.
     *
     * @param int $userid
     * @return object|null
     */
    private function load_user_identity_record(int $userid): ?object {
        global $DB;

        if ($userid <= 0) {
            return null;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname,email', IGNORE_MISSING);
        return $user ?: null;
    }

    /**
     * Build normalized identity variants from a Moodle user record.
     *
     * @param object $user
     * @param array $observedvariants
     * @return array
     */
    private function build_identity_variants_from_user_record(object $user, array $observedvariants = []): array {
        $variants = [];
        $firstname = trim((string)($user->firstname ?? ''));
        $lastname = trim((string)($user->lastname ?? ''));
        $email = trim((string)($user->email ?? ''));
        $fullname = trim($firstname . ' ' . $lastname);

        if ($firstname !== '') {
            $variants['firstname'] = $firstname;
        }
        if ($lastname !== '') {
            $variants['lastname'] = $lastname;
        }
        if ($fullname !== '') {
            $variants['both'] = $fullname;
        }
        if ($email !== '') {
            $variants['email'] = $email;
        }

        return $this->merge_identity_variants($variants, $observedvariants);
    }

    /**
     * Merge observed variants into the stored variant set without dropping known values.
     *
     * @param array $basevariants
     * @param array $incomingvariants
     * @return array
     */
    private function merge_identity_variants(array $basevariants, array $incomingvariants): array {
        foreach ($incomingvariants as $key => $variant) {
            if (!is_string($key)) {
                continue;
            }
            $variant = trim((string)$variant);
            if ($variant === '') {
                continue;
            }
            $basevariants[$key] = $variant;
        }

        return $basevariants;
    }

    /**
     * Check if array has structured person identity keys.
     *
     * @param array $value
     * @return bool
     */
    private function array_contains_person_identity_fields(array $value): bool {
        foreach (self::PERSON_IDENTITY_FIELDS as $field) {
            if (array_key_exists($field, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Anonymize firstname/lastname/email as one identity group when present in a structured row.
     *
     * @param array $value
     * @param array $tokenmap
     * @return array
     */
    private function anonymize_person_identity_field_group(array $value, array &$tokenmap): array {
        $variants = [];
        foreach (self::PERSON_IDENTITY_FIELDS as $field) {
            if (!array_key_exists($field, $value) || !is_string($value[$field])) {
                continue;
            }

            $raw = trim((string)$value[$field]);
            if ($raw === '' || self::looks_like_anon_token($raw)) {
                continue;
            }

            $variants[$field] = $raw;
        }

        if (empty($variants)) {
            return $value;
        }

        $identitykey = '';
        $userid = (int)($value['userid'] ?? $value['id'] ?? 0);
        if ($userid > 0) {
            $identitykey = 'user:' . $userid;
        } else if (!empty($variants['email'])) {
            $identity = $this->resolve_identity_from_email((string)$variants['email']);
            $identitykey = (string)($identity['identitykey'] ?? '');
        }

        if ($identitykey === '') {
            $seed = json_encode($variants);
            $identitykey = 'literal:' . sha1((string)$seed);
        }

        foreach ($variants as $field => $raw) {
            $value[$field] = $this->get_or_create_token(
                $tokenmap,
                $identitykey,
                (string)$field,
                (string)$raw,
                (string)$raw,
                $variants
            );
        }

        return $value;
    }

    /**
     * Check whether a field semantically refers to a user identity.
     *
     * @param string $normalizedfield
     * @return bool
     */
    private function is_user_reference_field(string $normalizedfield): bool {
        if ($normalizedfield === '') {
            return false;
        }

        if (in_array($normalizedfield, self::USER_REFERENCE_FIELDS, true)) {
            return true;
        }

        return str_ends_with($normalizedfield, 'userquery');
    }
}
