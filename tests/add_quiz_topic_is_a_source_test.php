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

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;

/**
 * The content field of course.add_quiz tells the constructor that a topic the user named is a question source.
 *
 * Found on demo2 (threads 86 and 88, 2026-11-19): "a quiz with ten questions about Graz" was built without content,
 * the topic went into an invented quiz name and the user was asked for a question source they had already given. The
 * field text listed "a topic, facts or document text" as source material; replayed on the recorded constructor calls
 * the topic reached content in 2 to 6 of 15 calls. With the text pinned here it is 15 of 15 for the first request, and
 * a quiz from an uploaded document still passes the document text (15 of 15), which a changed addquestions text broke
 * (the file name instead of the text). So the document text stays first and addquestions stays as it is.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\course\skills\add_quiz_skill
 */
final class add_quiz_topic_is_a_source_test extends \advanced_testcase {
    /**
     * The content field names the user's topic as a source, after the document text, and keeps its last sentence.
     */
    public function test_content_field_names_the_topic_as_a_source(): void {
        $properties = (array)((new add_quiz_skill())->get_schema()['properties'] ?? []);
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)($properties['content']['description'] ?? '')));

        $this->assertStringContainsString('the topic they named - a source too', $text);
        $document = strpos($text, 'document text');
        $topic = strpos($text, 'the topic they named');
        $this->assertNotFalse($document);
        $this->assertLessThan($topic, $document, 'the document text is named before the topic');
        $this->assertStringEndsWith('Never write the questions yourself.', $text);
    }

    /**
     * The addquestions field is the measured one: a text that lists the sources made the constructor pass the file
     * name of an uploaded document as content.
     */
    public function test_addquestions_field_does_not_list_the_sources(): void {
        $properties = (array)((new add_quiz_skill())->get_schema()['properties'] ?? []);
        $text = (string)($properties['addquestions']['description'] ?? '');

        $this->assertStringContainsString('has not said from where', $text);
        $this->assertStringNotContainsString('document', $text);
        $this->assertStringNotContainsString('topic', $text);
    }
}
