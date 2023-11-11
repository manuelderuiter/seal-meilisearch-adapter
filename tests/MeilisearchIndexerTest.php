<?php

declare(strict_types=1);

/*
 * This file is part of the Schranz Search package.
 *
 * (c) Alexander Schranz <alexander@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Schranz\Search\SEAL\Adapter\Meilisearch\Tests;

use Schranz\Search\SEAL\Adapter\Meilisearch\MeilisearchAdapter;
use Schranz\Search\SEAL\Search\Condition;
use Schranz\Search\SEAL\Search\SearchBuilder;
use Schranz\Search\SEAL\Testing\AbstractIndexerTestCase;
use Schranz\Search\SEAL\Testing\TaskHelper;
use Schranz\Search\SEAL\Testing\TestingHelper;

class MeilisearchIndexerTest extends AbstractIndexerTestCase
{
    private static TaskHelper $taskHelper;

    protected function setUp(): void
    {
        parent::setUp();
        self::$taskHelper = new TaskHelper();
    }

    public static function setUpBeforeClass(): void
    {
        $client = ClientHelper::getClient();
        self::$adapter = new MeilisearchAdapter($client);

        parent::setUpBeforeClass();
    }

    public function testFindSimpleDocumentsWithBooleanValueTrue(): void
    {
        $schema = TestingHelper::createSchema();

        foreach (self::createSimpleFixtures() as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_SIMPLE],
                $document,
                ['return_slow_promise_result' => true],
            );
        }

        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_SIMPLE);
        $search->addFilter(new Condition\EqualCondition('is_online', true));

        $result = $search->getResult();
        $this->assertEquals(2, $result->total());
    }

    public function testFindSimpleDocumentsWithBooleanValueFalse(): void
    {
        $schema = TestingHelper::createSchema();

        foreach (self::createSimpleFixtures() as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_SIMPLE],
                $document,
                ['return_slow_promise_result' => true],
            );
        }

        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_SIMPLE);
        $search->addFilter(new Condition\EqualCondition('is_online', false));

        $result = $search->getResult();
        $this->assertEquals(1, $result->total());
    }

    public function testFindSimpleDocumentsWithAuthor(): void
    {
        $schema = TestingHelper::createSchema();

        foreach (self::createSimpleFixtures() as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_SIMPLE],
                $document,
                ['return_slow_promise_result' => true],
            );
        }

        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_SIMPLE);
        $search->addFilter(new Condition\EqualCondition('author', 'Jane Doe'));

        $result = $search->getResult();
        $this->assertEquals(1, $result->total());

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_SIMPLE);
        $search->addFilter(new Condition\EqualCondition('author', 'A.'));

        $result = $search->getResult();
        $this->assertEquals(1, $result->total());
    }

    /**
     * @return array<array{
     *     id: string,
     *     title?: string|null,
     *     is_online?: bool,
     *     author?: string
     * }>
     */
    private static function createSimpleFixtures(): array
    {
        return [
            [
                'id' => '1',
                'title' => 'Simple Title',
            ],
            [
                'id' => '2',
                'title' => 'Other Title',
            ],
            [
                'id' => '3',
            ],
            [
                'id' => '4',
                'title' => 'Simple Title',
                'is_online' => true,
                'author' => 'John Doe',
            ],
            [
                'id' => '5',
                'title' => 'Other Title',
                'is_online' => false,
            ],
            [
                'id' => '6',
                'title' => 'Simple Title',
                'is_online' => true,
                'author' => 'Jane Doe',
            ],
            [
                'id' => '7',
                'title' => 'Simple Title',
                'author' => 'A.',
            ],
        ];
    }
}
