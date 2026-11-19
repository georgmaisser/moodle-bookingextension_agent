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

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\course\skills\find_course_images_skill;
use bookingextension_agent\local\wizard\course\skills\set_course_image_skill;
use bookingextension_agent\local\wizard\services\images\course_image_store;
use bookingextension_agent\local\wizard\services\images\image_candidates_preview;
use bookingextension_agent\local\wizard\services\images\image_check_service;
use bookingextension_agent\local\wizard\services\images\image_http_client;
use bookingextension_agent\local\wizard\services\images\image_license_policy;
use bookingextension_agent\local\wizard\services\images\image_search_service;
use bookingextension_agent\local\wizard\services\images\wikimedia_commons_source;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use context_course;

/**
 * The image search: library hits are filtered by facts, every image is checked by the model, the decision is taken
 * in code, and the chosen image lands in the course with its credit.
 *
 * No test reaches an image library or a model: the HTTP transport and the model replies are scripted.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\course\skills\find_course_images_skill
 * @covers \bookingextension_agent\local\wizard\course\skills\set_course_image_skill
 * @covers \bookingextension_agent\local\wizard\services\images\course_image_store
 * @covers \bookingextension_agent\local\wizard\services\images\image_candidates_preview
 * @covers \bookingextension_agent\local\wizard\services\images\image_check_service
 * @covers \bookingextension_agent\local\wizard\services\images\image_http_client
 * @covers \bookingextension_agent\local\wizard\services\images\image_license_policy
 * @covers \bookingextension_agent\local\wizard\services\images\image_search_service
 * @covers \bookingextension_agent\local\wizard\services\images\wikimedia_commons_source
 */
final class image_search_test extends \advanced_testcase {
    /** @var string[] URLs the scripted transport was asked for. */
    private array $requests = [];

    /** @var string[] Prompts the scripted model received, by source. */
    private array $prompts = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('imagesearch_enabled', 1, 'bookingextension_agent');
        $this->requests = [];
        $this->prompts = [];
    }

    protected function tearDown(): void {
        image_http_client::set_test_transport(null);
        llm_call_service::set_test_responder(null);
        parent::tearDown();
    }

    /**
     * A JPEG of the given size.
     *
     * @param int $width
     * @param int $height
     * @return string
     */
    private function jpeg(int $width = 64, int $height = 40): string {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 160, 20));
        ob_start();
        imagejpeg($image);
        return (string)ob_get_clean();
    }

    /**
     * One page of a Commons API answer.
     *
     * @param int $pageid
     * @param int $index
     * @param array $overrides Keys: title, width, height, mime, license, short, artist.
     * @return array
     */
    private function page(int $pageid, int $index, array $overrides = []): array {
        $o = $overrides + [
            'title' => 'File:Bee ' . $pageid . '.jpg', 'width' => 2000, 'height' => 1200, 'mime' => 'image/jpeg',
            'license' => 'cc-by-sa-4.0', 'short' => 'CC BY-SA 4.0', 'artist' => '<a href="//x">Ada &amp; Bob</a>',
        ];
        return [
            'pageid' => $pageid,
            'title' => $o['title'],
            'index' => $index,
            'imageinfo' => [[
                'thumburl' => 'https://upload.example/thumb/' . $pageid . '.jpg',
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:' . $pageid,
                'width' => $o['width'],
                'height' => $o['height'],
                'mime' => $o['mime'],
                'extmetadata' => [
                    'License' => ['value' => $o['license']],
                    'LicenseShortName' => ['value' => $o['short']],
                    'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0'],
                    'Artist' => ['value' => $o['artist']],
                    'AttributionRequired' => ['value' => 'true'],
                ],
            ]],
        ];
    }

    /**
     * Script the library: every API request returns these pages, every other URL a JPEG.
     *
     * @param array[] $pages
     * @return void
     */
    private function script_library(array $pages): void {
        image_http_client::set_test_transport(function (string $url, string $useragent) use ($pages): array {
            $this->requests[] = $url;
            if (strpos($url, 'https://commons.wikimedia.org/w/api.php?') === 0) {
                parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
                $selected = $pages;
                if (isset($params['pageids'])) {
                    $wanted = (int)$params['pageids'];
                    $selected = array_values(array_filter($pages, static fn(array $p): bool => (int)$p['pageid'] === $wanted));
                }
                $keyed = [];
                foreach ($selected as $page) {
                    $keyed[(string)$page['pageid']] = $page;
                }
                return ['code' => 200, 'body' => json_encode(['query' => ['pages' => $keyed]])];
            }
            return ['code' => 200, 'body' => $this->jpeg()];
        });
    }

    /**
     * Script the model: the search plan, then one reply per image check, in order.
     *
     * @param string[] $checkreplies
     * @param string $plan
     * @return void
     */
    private function script_model(
        array $checkreplies,
        string $plan = '{"subject":"A bee on a flower.","queries":["bee flower"]}'
    ): void {
        $responder = function (string $actionclass, string $prompt, string $source) use (&$checkreplies, $plan) {
            $this->prompts[$source][] = $prompt;
            if ($source === 'image_search_plan') {
                return $plan;
            }
            return (string)array_shift($checkreplies);
        };
        llm_call_service::set_test_responder($responder);
    }

    /**
     * Only public domain, CC0, CC BY and CC BY-SA are reusable; the code of the library decides.
     */
    public function test_the_licence_policy_follows_the_machine_code(): void {
        foreach (['pd', 'cc0', 'cc-zero', 'cc-by-4.0', 'cc-by-sa-3.0', 'CC-BY-2.0', 'pd-usgov'] as $code) {
            $this->assertTrue(image_license_policy::is_reusable($code), $code);
        }
        foreach (['', 'cc-by-nc-4.0', 'cc-by-nd-2.0', 'cc-by-nc-sa-4.0', 'gfdl', 'fal', 'copyrighted'] as $code) {
            $this->assertFalse(image_license_policy::is_reusable($code), $code);
        }
        $this->assertSame('cc-sa-4.0', image_license_policy::moodle_license('cc-by-sa-4.0'));
        $this->assertSame('unknown', image_license_policy::moodle_license('cc-by-sa-3.0'));
        $this->assertSame('public', image_license_policy::moodle_license('cc0'));
    }

    /**
     * The library answer becomes candidates in the order of the hits; markup in the author field is removed.
     */
    public function test_the_commons_source_reads_the_hits(): void {
        $this->script_library([$this->page(22, 2), $this->page(11, 1, ['title' => 'File:Honey bee on lavender.jpg'])]);
        $found = (new wikimedia_commons_source())->search('bee flower', 5);

        $this->assertSame('', $found['error']);
        $this->assertSame(['commons:11', 'commons:22'], array_column($found['candidates'], 'ref'));
        $first = $found['candidates'][0];
        $this->assertSame('Honey bee on lavender', $first['title']);
        $this->assertSame('Ada & Bob', $first['author']);
        $this->assertSame('cc-by-sa-4.0', $first['licensecode']);
        $this->assertSame('CC BY-SA 4.0', $first['license']);
        // The URL uses the plain separator although Moodle sets arg_separator.output to "&amp;".
        $this->assertStringNotContainsString('&amp;', $this->requests[0]);
        $this->assertStringContainsString('gsrsearch=bee+flower+filetype%3Abitmap', $this->requests[0]);
        $this->assertSame(11, wikimedia_commons_source::pageid_of_ref('commons:11'));
        $this->assertSame(0, wikimedia_commons_source::pageid_of_ref('commons:11; drop'));
        $this->assertSame(0, wikimedia_commons_source::pageid_of_ref('other:11'));
    }

    /**
     * The decision comes from the scores and the problem list; a verdict of the model is not read.
     */
    public function test_the_decision_is_taken_in_code(): void {
        $this->assertTrue(image_check_service::decide(2, 2, []));
        $this->assertTrue(image_check_service::decide(3, 3, ['diagram_or_chart']));
        $this->assertFalse(image_check_service::decide(1, 3, []), 'subject not shown');
        $this->assertFalse(image_check_service::decide(3, 1, []), 'does not fit the course');
        $this->assertFalse(image_check_service::decide(3, 3, ['identifiable_person']));
        $this->assertFalse(image_check_service::decide(3, 3, ['text_or_logo']));

        // The model says "accept" but reports a low subject score: rejected. A reply without scores: not checked.
        $this->script_model([
            '{"shows":"A valley.","subject_match":1,"context_fit":3,"problems":[],"verdict":"accept"}',
            'Sorry, I cannot see the image.',
            '{"shows":"A bee.","subject_match":3,"context_fit":3,"problems":["made_up_problem"]}',
        ]);
        $checker = new image_check_service(new conversation_store());
        $context = \context_system::instance()->id;
        $low = $checker->check($this->jpeg(), 'image/jpeg', 'A bee.', 'Course title: Bees', 0, $context, 2);
        $this->assertTrue($low['checked']);
        $this->assertFalse($low['accepted']);
        $unreadable = $checker->check($this->jpeg(), 'image/jpeg', 'A bee.', 'Course title: Bees', 0, $context, 2);
        $this->assertFalse($unreadable['checked']);
        $this->assertFalse($unreadable['accepted']);
        $good = $checker->check($this->jpeg(), 'image/jpeg', 'A bee.', 'Course title: Bees', 0, $context, 2);
        $this->assertTrue($good['accepted']);
        $this->assertSame([], $good['problems'], 'a problem outside the declared list is dropped');
    }

    /**
     * What the user asked for is offered when the image shows it, whatever the course is about; what the wizard
     * derives from the course itself still has to fit the course.
     */
    public function test_a_subject_the_user_asked_for_need_not_fit_the_course(): void {
        $this->assertTrue(image_check_service::decide(3, 0, ['misleading', 'wrong_place_or_era'], true));
        $this->assertFalse(image_check_service::decide(3, 0, ['misleading'], false));
        $this->assertFalse(image_check_service::decide(1, 3, [], true), 'the wish itself is not shown');
        $this->assertFalse(image_check_service::decide(3, 0, ['identifiable_person'], true));
        $this->assertFalse(image_check_service::decide(3, 0, ['offensive'], true));

        $surfer = '{"shows":"A surfer on a wave.","subject_match":3,"context_fit":0,"problems":["misleading"]}';
        $this->script_library([$this->page(1, 1)]);
        $this->script_model([$surfer], '{"subject":"A surfer riding a wave.","queries":["surfer wave"]}');
        $service = new image_search_service(new conversation_store());
        $context = \context_system::instance()->id;

        $asked = $service->find('Course title: Biology', 'a surfer on a big wave', 0, $context, 2);
        $this->assertSame(['commons:1'], array_column($asked['accepted'], 'ref'));
        $this->assertSame(0, $asked['accepted'][0]['check']['context_fit'], 'the skill can still say it is off topic');

        $this->script_model([$surfer], '{"subject":"A surfer riding a wave.","queries":["surfer wave"]}');
        $derived = $service->find('Course title: Biology', '', 0, $context, 2);
        $this->assertSame([], $derived['accepted']);
        $this->assertSame(1, $derived['rejected']);
    }

    /**
     * Hits that fail on facts never reach the model; of the checked ones only the accepted are returned.
     */
    public function test_the_search_returns_only_checked_and_accepted_images(): void {
        $this->script_library([
            $this->page(1, 1),
            $this->page(2, 2, ['license' => 'cc-by-nc-4.0']),
            $this->page(3, 3, ['width' => 400, 'height' => 300]),
            $this->page(4, 4, ['width' => 900, 'height' => 1600]),
            $this->page(5, 5, ['mime' => 'image/svg+xml']),
            $this->page(6, 6),
        ]);
        $this->script_model([
            '{"shows":"A metal part.","subject_match":0,"context_fit":0,"problems":["misleading"]}',
            '{"shows":"A bee on a flower.","subject_match":3,"context_fit":3,"problems":[]}',
        ]);
        $found = (new image_search_service(new conversation_store()))
            ->find('Course title: Bees', '', 0, \context_system::instance()->id, 2);

        $this->assertSame('', $found['error']);
        $this->assertSame(2, $found['checked'], 'only the two hits that pass the facts are shown to the model');
        $this->assertSame(1, $found['rejected']);
        $this->assertSame(['commons:6'], array_column($found['accepted'], 'ref'));
        $this->assertCount(2, $this->prompts['image_check']);
        $this->assertStringContainsString('Wanted: A bee on a flower.', $this->prompts['image_check'][0]);
    }

    /**
     * A query that carries a privacy placeholder is not sent to the library.
     */
    public function test_a_query_with_a_placeholder_never_leaves_the_site(): void {
        $this->script_library([$this->page(1, 1)]);
        $this->script_model(
            ['{"shows":"A bee.","subject_match":3,"context_fit":3,"problems":[]}'],
            '{"subject":"A portrait.","queries":["ANON_USER_1_both portrait","bee flower"]}'
        );
        (new image_search_service(new conversation_store()))->find('Course title: Bees', '', 0, \context_system::instance()->id, 2);

        $searches = array_filter($this->requests, static fn(string $url): bool => strpos($url, 'gsrsearch') !== false);
        $this->assertCount(1, $searches);
        $this->assertStringNotContainsString('ANON_', implode(' ', $this->requests));
    }

    /**
     * The find skill shows the accepted candidates with their reference and a picture, and stores nothing.
     */
    public function test_the_find_skill_shows_candidates_and_stores_nothing(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Bees', 'summary' => 'About bees.']);
        $contextid = context_course::instance($course->id)->id;
        $this->script_library([$this->page(7, 1, ['title' => 'File:Bee on lavender.jpg'])]);
        $this->script_model(['{"shows":"A bee on lavender.","subject_match":3,"context_fit":3,"problems":[]}']);

        $skill = new find_course_images_skill();
        $result = $skill->execute([], $contextid, (int)get_admin()->id);

        $this->assertSame('executed', $result['status']);
        $this->assertStringContainsString('imageref=commons:7', $result['observation_full']);
        $this->assertStringContainsString('A bee on lavender.', $result['observation_full']);
        $this->assertStringContainsString('Ada & Bob', $result['observation_full']);
        $this->assertSame([], $result['observation_persons']);
        $preview = $skill->get_result_preview($result, $contextid, (int)get_admin()->id);
        $this->assertSame(image_candidates_preview::PREVIEW_TYPE, $preview['type']);
        $this->assertStringContainsString('src="data:image/jpeg;base64,', $preview['html']);
        $this->assertStringNotContainsString('upload.example', $preview['html'], 'the browser contacts no library');
        $this->assertFalse(course_image_store::has_image((int)$course->id));
        $this->assertStringContainsString('Course title: Bees', $this->prompts['image_search_plan'][0]);
    }

    /**
     * With the image search switched off nothing is searched.
     */
    public function test_the_find_skill_does_nothing_while_the_search_is_off(): void {
        set_config('imagesearch_enabled', 0, 'bookingextension_agent');
        $course = $this->getDataGenerator()->create_course();
        $this->script_library([$this->page(7, 1)]);
        $this->script_model([]);

        $result = (new find_course_images_skill())->execute([], context_course::instance($course->id)->id, (int)get_admin()->id);

        $this->assertSame('error', $result['status']);
        $this->assertSame('feature_disabled', $result['error_class']);
        $this->assertSame([], $this->requests);
        $this->assertSame([], $this->prompts);
    }

    /**
     * Run the preflight of the set skill.
     *
     * @param array $input
     * @param int $contextid
     * @return array
     */
    private function preflight_set(array $input, int $contextid): array {
        $method = new \ReflectionMethod(set_course_image_skill::class, 'run_preflight');
        return $method->invoke(new set_course_image_skill(), $input, $contextid, (int)get_admin()->id);
    }

    /**
     * The set skill takes an image the search accepted, stores it with its credit and asks before it replaces one.
     */
    public function test_the_set_skill_stores_the_image_with_its_credit(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Bees', 'summary' => '<p>About bees.</p>']);
        $contextid = context_course::instance($course->id)->id;
        $this->script_library([
            $this->page(7, 1, ['title' => 'File:Bee on lavender.jpg']),
            $this->page(8, 2, ['title' => 'File:Honeycomb.jpg', 'artist' => 'Cy']),
        ]);
        $this->script_model([
            '{"shows":"A bee on lavender.","subject_match":3,"context_fit":3,"problems":[]}',
            '{"shows":"A honeycomb.","subject_match":3,"context_fit":3,"problems":[]}',
        ]);
        (new find_course_images_skill())->execute([], $contextid, (int)get_admin()->id);
        $modelcalls = count($this->prompts['image_check']);
        $requests = count($this->requests);
        $skill = new set_course_image_skill();

        $preflight = $this->preflight_set(['imageref' => 'commons:7'], $contextid);
        $this->assertSame('pass', $preflight['status']);
        $prepared = $preflight['prepared_input'];
        $this->assertSame('A bee on lavender.', $prepared['imageshows']);
        $this->assertFalse($prepared['replacesimage']);
        // The preflight has a short time budget: it asks neither the library nor the model.
        $this->assertCount($modelcalls, $this->prompts['image_check']);
        $this->assertCount($requests, $this->requests);

        $result = $skill->execute($prepared, $contextid, (int)get_admin()->id);
        $this->assertSame('executed', $result['status']);
        $files = get_file_storage()->get_area_files($contextid, 'course', 'overviewfiles', 0, 'filename', false);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('Bee-on-lavender.jpg', $file->get_filename());
        $this->assertSame('Ada & Bob', $file->get_author());
        $this->assertSame('cc-sa-4.0', $file->get_license());
        $summary = $DB->get_field('course', 'summary', ['id' => $course->id]);
        $this->assertStringContainsString('<p>About bees.</p>', $summary);
        $this->assertStringContainsString('Bee on lavender', $summary);
        $this->assertStringContainsString('CC BY-SA 4.0', $summary);

        // A second image: a confirmation first, then the image and the credit line are replaced.
        $blocked = $this->preflight_set(['imageref' => 'commons:8'], $contextid);
        $this->assertSame('invalid', $blocked['status']);
        $this->assertSame('COURSE_IMAGE_REPLACE_CONFIRM_REQUIRED', $blocked['issues'][0]['code']);
        $this->assertSame('needs_confirmation', $blocked['issues'][0]['severity']);

        $confirmed = $this->preflight_set(
            ['imageref' => 'commons:8', 'override' => [set_course_image_skill::OVERRIDE_REPLACE]],
            $contextid
        );
        $this->assertSame('pass', $confirmed['status']);
        $skill->execute($confirmed['prepared_input'], $contextid, (int)get_admin()->id);
        $files = get_file_storage()->get_area_files($contextid, 'course', 'overviewfiles', 0, 'filename', false);
        $this->assertCount(1, $files);
        $this->assertSame('Honeycomb.jpg', reset($files)->get_filename());
        $summary = $DB->get_field('course', 'summary', ['id' => $course->id]);
        $this->assertSame(1, substr_count($summary, course_image_store::CREDIT_CLASS));
        $this->assertStringNotContainsString('Bee on lavender', $summary);
        $this->assertStringContainsString('Honeycomb', $summary);
    }

    /**
     * An image the model did not accept for this course is never stored, wherever its reference comes from.
     */
    public function test_the_set_skill_takes_only_what_the_search_accepted_for_the_course(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Bees']);
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Fire safety']);
        $contextid = context_course::instance($course->id)->id;
        $this->script_library([$this->page(7, 1), $this->page(8, 2)]);
        $this->script_model([
            '{"shows":"A bee.","subject_match":3,"context_fit":3,"problems":[]}',
            '{"shows":"A named person.","subject_match":3,"context_fit":3,"problems":["identifiable_person"]}',
        ]);
        (new find_course_images_skill())->execute([], $contextid, (int)get_admin()->id);

        // Rejected by the check, never searched, and accepted for another course.
        $rejected = $this->preflight_set(['imageref' => 'commons:8'], $contextid);
        $this->assertSame('IMAGE_NOT_CHECKED', $rejected['issues'][0]['code']);
        $unknown = $this->preflight_set(['imageref' => 'commons:12345'], $contextid);
        $this->assertSame('IMAGE_NOT_CHECKED', $unknown['issues'][0]['code']);
        $othercourse = $this->preflight_set(['imageref' => 'commons:7'], context_course::instance($other->id)->id);
        $this->assertSame('IMAGE_NOT_CHECKED', $othercourse['issues'][0]['code']);
        $this->assertSame('pass', $this->preflight_set(['imageref' => 'commons:7'], $contextid)['status']);

        $structure = (new set_course_image_skill())->check_structure(['imageref' => 'the second one']);
        $this->assertFalse($structure['valid']);
        $this->assertSame(['RECOVERABLE_INPUT_ERROR'], $structure['issue_codes']);
        $this->assertFalse(course_image_store::has_image((int)$course->id));
    }

    /**
     * Data that is not a JPEG or PNG image is never stored.
     */
    public function test_the_store_refuses_data_that_is_no_image(): void {
        $course = $this->getDataGenerator()->create_course();
        $stored = (new course_image_store())
            ->store($course, ['title' => 'x'], '<svg onload="alert(1)"></svg>', (int)get_admin()->id);

        $this->assertFalse($stored['success']);
        $this->assertFalse(course_image_store::has_image((int)$course->id));
        $this->assertNull(course_image_store::identify('GIF89a'));
    }
}
