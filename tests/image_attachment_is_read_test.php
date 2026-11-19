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

declare(strict_types=1);

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\attachment\attachment_processor;
use bookingextension_agent\local\wizard\services\attachment\attachment_token_service;
use bookingextension_agent\local\wizard\services\attachment\image_description_service;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;

/**
 * An attached image reaches the agent as what a model saw in it, masked like document text.
 *
 * No test reaches a model: the replies are scripted.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\attachment\attachment_processor
 * @covers \bookingextension_agent\local\wizard\services\attachment\image_description_service
 */
final class image_attachment_is_read_test extends \advanced_testcase {
    /** @var string[] Prompts the scripted model received. */
    private array $prompts = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->prompts = [];
    }

    protected function tearDown(): void {
        llm_call_service::set_test_responder(null);
        parent::tearDown();
    }

    /**
     * Script the model with one reply for every image it is shown.
     *
     * @param string $reply
     * @return void
     */
    private function script_model(string $reply): void {
        llm_call_service::set_test_responder(function (string $actionclass, string $prompt, string $source) use ($reply) {
            $this->prompts[$source][] = $prompt;
            return $reply;
        });
    }

    /**
     * An uploaded image and its token.
     *
     * @param int $contextid
     * @param string|null $bytes File content; a small PNG when null.
     * @return string The token.
     */
    private function upload(int $contextid, ?string $bytes = null): string {
        global $USER;
        if ($bytes === null) {
            $image = imagecreatetruecolor(40, 20);
            ob_start();
            imagepng($image);
            $bytes = (string)ob_get_clean();
            imagedestroy($image);
        }
        $path = make_request_directory() . '/notice.png';
        file_put_contents($path, $bytes);
        return (new attachment_token_service())->create((int)$USER->id, $contextid, $path, 'image/png', 'notice.png');
    }

    /**
     * Run the processor on one message with one image.
     *
     * @param string $message
     * @param string $token
     * @param bool $withdescriber
     * @return string
     */
    private function augment(string $message, string $token, bool $withdescriber = true): string {
        global $USER;
        $contextid = (int)\context_system::instance()->id;
        $store = new conversation_store();
        $threadid = (int)$store->create_fresh_thread((int)$USER->id, $contextid)->id;
        return (new attachment_processor())->augment_message(
            $message,
            [['token' => $token, 'type' => 'image']],
            (int)$USER->id,
            $contextid,
            new privacy_anonymizer($store),
            $threadid,
            $withdescriber ? new image_description_service($store) : null
        );
    }

    public function test_what_the_model_saw_follows_the_request(): void {
        set_config('imageinput_enabled', 1, 'bookingextension_agent');
        $this->script_model('{"shows":"A printed notice on a board.","text":"Yoga\nMonday 18:00"}');
        $token = $this->upload((int)\context_system::instance()->id);

        $augmented = $this->augment('Create a course from this notice.', $token);

        $this->assertStringStartsWith('Create a course from this notice.', $augmented);
        $this->assertStringContainsString("[Attachment: notice.png — Attachment-Token: {$token}]", $augmented);
        $this->assertStringContainsString(
            "--- IMAGE: notice.png ---\nShows: A printed notice on a board.\nText in the image:\nYoga\nMonday 18:00\n"
                . "--- END IMAGE ---",
            $augmented
        );
        // The model was told what the user wants to know, and the image keeps its token for a skill.
        $this->assertStringContainsString('Create a course from this notice.', $this->prompts['image_attachment'][0]);
        $resolved = (new attachment_token_service())->resolve($token, 2, (int)\context_system::instance()->id);
        $this->assertSame('notice.png', $resolved['filename']);
    }

    public function test_the_image_stays_a_hint_while_reading_is_off(): void {
        $this->script_model('{"shows":"A printed notice.","text":""}');
        $token = $this->upload((int)\context_system::instance()->id);

        $augmented = $this->augment('Use this as the header image.', $token);

        $this->assertSame("Use this as the header image.\n\n[Attachment: notice.png — Attachment-Token: {$token}]", $augmented);
        $this->assertSame([], $this->prompts);
    }

    public function test_a_name_the_model_read_in_the_image_is_masked(): void {
        set_config('imageinput_enabled', 1, 'bookingextension_agent');
        $this->getDataGenerator()->create_user(['firstname' => 'Marlene', 'lastname' => 'Oberhuber']);
        $this->script_model('{"shows":"A list of participants.","text":"Marlene Oberhuber\nroom 4"}');
        $token = $this->upload((int)\context_system::instance()->id);

        $augmented = $this->augment('Who is on this list?', $token);

        $this->assertStringContainsString('--- IMAGE: notice.png ---', $augmented);
        $this->assertStringContainsString('room 4', $augmented);
        $this->assertStringNotContainsString('Oberhuber', $augmented);
    }

    public function test_an_unreadable_reply_or_file_leaves_the_hint_only(): void {
        set_config('imageinput_enabled', 1, 'bookingextension_agent');
        $contextid = (int)\context_system::instance()->id;

        $this->script_model('I cannot help with that.');
        $token = $this->upload($contextid);
        $this->assertStringNotContainsString('--- IMAGE', $this->augment('What is this?', $token));

        // A file that is no image is never sent to the model.
        $this->prompts = [];
        $this->script_model('{"shows":"Something.","text":""}');
        $token = $this->upload($contextid, 'this is not an image');
        $this->assertStringNotContainsString('--- IMAGE', $this->augment('What is this?', $token));
        $this->assertSame([], $this->prompts);
    }

    public function test_the_rendition_is_a_jpeg_within_the_size_limit(): void {
        $image = imagecreatetruecolor(3000, 1500);
        ob_start();
        imagepng($image);
        $png = (string)ob_get_clean();
        imagedestroy($image);

        $jpeg = image_description_service::rendition($png);
        $info = getimagesizefromstring($jpeg);

        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame(image_description_service::MAX_EDGE, $info[0]);
        $this->assertSame(image_description_service::MAX_EDGE / 2, $info[1]);
        $this->assertSame('', image_description_service::rendition('no image'));
    }
}
