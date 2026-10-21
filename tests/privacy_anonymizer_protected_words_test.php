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
 * Protected words of the privacy anonymizer come from the setting only.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;

/**
 * The shipped word list is only the DEFAULT of `aiprivacyprotectedwords`: at runtime the
 * anonymizer consults the setting alone, so an admin can edit or empty the list and no
 * hardcoded word is merged in behind their back.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class privacy_anonymizer_protected_words_test extends \advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Create a user whose lastname is a word of the shipped default list, a thread and the anonymizer.
     *
     * @return array{0: privacy_anonymizer, 1: int}
     */
    private function prepare(): array {
        global $USER;
        $this->setAdminUser();
        $this->getDataGenerator()->create_user([
            'firstname' => 'Ottokar',
            'lastname' => 'Bitte',
            'email' => 'ottokar.bitte@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        \cache::make('bookingextension_agent', 'aiprivacynames')->purge();

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id)->id;

        return [new privacy_anonymizer($store), $threadid];
    }

    /**
     * The shipped default is a public constant the settings page ships verbatim and it contains
     * the fixture word, so the other tests exercise a default-list word.
     */
    public function test_default_list_is_public_and_contains_fixture_word(): void {
        $this->assertContains('bitte', privacy_anonymizer::PROTECTED_WORDS_DEFAULT);
        $this->assertFalse(defined(privacy_anonymizer::class . '::NAME_STOPWORDS'), 'No hidden stopword list.');
    }

    /**
     * The shipped default is a clean example list: lowercase, trimmed, no duplicates, and it
     * carries examples for every language the agent ships (de, en, fr, it, es).
     */
    public function test_default_list_is_normalized_and_multilingual(): void {
        $default = privacy_anonymizer::PROTECTED_WORDS_DEFAULT;

        foreach ($default as $word) {
            $this->assertSame(\core_text::strtolower(trim($word)), $word, "Default entry '$word' must be lowercase and trimmed.");
        }
        $this->assertSame(array_values(array_unique($default)), $default, 'The default list must not repeat entries.');
        foreach (['bitte', 'with', 'pour', 'questo', 'para'] as $example) {
            $this->assertContains($example, $default);
        }
    }

    /**
     * With the shipped default in the setting the word is protected.
     */
    public function test_default_setting_protects_the_word(): void {
        $this->resetAfterTest();
        set_config('aiprivacyprotectedwords', implode(', ', privacy_anonymizer::PROTECTED_WORDS_DEFAULT), 'bookingextension_agent');
        [$anonymizer, $threadid] = $this->prepare();

        $result = $anonymizer->precheck_user_message($threadid, 'Zeig mir bitte die offenen Kurse.');
        $this->assertSame('Zeig mir bitte die offenen Kurse.', (string)$result['sanitizedmessage']);
    }

    /**
     * An emptied setting protects nothing: the word collides with the user's lastname and is masked.
     */
    public function test_empty_setting_masks_the_word(): void {
        $this->resetAfterTest();
        set_config('aiprivacyprotectedwords', '', 'bookingextension_agent');
        [$anonymizer, $threadid] = $this->prepare();

        $result = $anonymizer->precheck_user_message($threadid, 'Zeig mir bitte die offenen Kurse.');
        $this->assertStringNotContainsString('bitte', (string)$result['sanitizedmessage']);
        $this->assertTrue(privacy_anonymizer::looks_like_anon_token((string)$result['sanitizedmessage']));
    }

    /**
     * A site-specific list replaces the default entirely: only the configured words are protected.
     */
    public function test_custom_setting_replaces_the_default(): void {
        $this->resetAfterTest();
        set_config('aiprivacyprotectedwords', "kurse\nadmin", 'bookingextension_agent');
        [$anonymizer, $threadid] = $this->prepare();

        $result = $anonymizer->precheck_user_message($threadid, 'Zeig mir bitte die offenen Kurse.');
        $sanitized = (string)$result['sanitizedmessage'];
        $this->assertStringNotContainsString('bitte', $sanitized);
        $this->assertStringContainsString('Kurse', $sanitized);
    }

    /**
     * German "alle" in all its forms ships in the default: "bei allen Buchungsoptionen" collided with a fixture
     * account's lastname Allen (#2614 class, found with #2680) and the bulk request never reached the skill.
     */
    public function test_default_list_contains_the_german_forms_of_all(): void {
        foreach (['alle', 'allem', 'allen', 'aller', 'alles'] as $word) {
            $this->assertContains($word, privacy_anonymizer::PROTECTED_WORDS_DEFAULT);
        }
    }

    /**
     * A protected word inside a sentence stays in clear text and raises no decision suspect, although an
     * account carries it as lastname.
     */
    public function test_protected_word_in_a_sentence_is_no_lastname_suspect(): void {
        $this->resetAfterTest();
        set_config('aiprivacyprotectedwords', implode(', ', privacy_anonymizer::PROTECTED_WORDS_DEFAULT), 'bookingextension_agent');
        $this->getDataGenerator()->create_user([
            'firstname' => 'Rachel',
            'lastname' => 'Allen',
            'email' => 'rachel.allen@example.com',
        ]);
        [$anonymizer, $threadid] = $this->prepare();

        $sentence = 'Setze bei allen Buchungsoptionen, die noch kein Kursformat haben, das Kursformat auf Präsenz.';
        $result = $anonymizer->precheck_user_message($threadid, $sentence);
        $this->assertSame($sentence, (string)$result['sanitizedmessage']);
        $this->assertSame([], (array)($result['suspects'] ?? $result['collisionsuspects'] ?? []));
    }

    /**
     * The full name of a real account is masked even when one of its parts is a protected word: the list guards
     * single words and must not leave "Allen" in clear text next to a masked first name.
     */
    public function test_full_name_of_a_real_account_wins_over_the_protected_list(): void {
        $this->resetAfterTest();
        set_config('aiprivacyprotectedwords', implode(', ', privacy_anonymizer::PROTECTED_WORDS_DEFAULT), 'bookingextension_agent');
        $this->getDataGenerator()->create_user([
            'firstname' => 'Rachel',
            'lastname' => 'Allen',
            'email' => 'rachel.allen@example.com',
        ]);
        [$anonymizer, $threadid] = $this->prepare();

        $result = $anonymizer->precheck_user_message($threadid, 'Buche Rachel Allen in den Pilates-Kurs ein.');
        $sanitized = (string)$result['sanitizedmessage'];
        $this->assertStringNotContainsString('Allen', $sanitized);
        $this->assertStringNotContainsString('Rachel', $sanitized);
        $this->assertMatchesRegularExpression('/ANON_USER_\d+_both/', $sanitized);
    }
}
