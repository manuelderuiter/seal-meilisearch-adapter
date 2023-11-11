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

namespace Schranz\Search\SEAL\Adapter\Meilisearch;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Schranz\Search\SEAL\Adapter\SearcherInterface;
use Schranz\Search\SEAL\Marshaller\Marshaller;
use Schranz\Search\SEAL\Schema\Exception\FieldByPathNotFoundException;
use Schranz\Search\SEAL\Schema\Field\BooleanField;
use Schranz\Search\SEAL\Schema\Field\IdentifierField;
use Schranz\Search\SEAL\Schema\Field\TextField;
use Schranz\Search\SEAL\Schema\Index;
use Schranz\Search\SEAL\Search\Condition;
use Schranz\Search\SEAL\Search\Result;
use Schranz\Search\SEAL\Search\Search;

final class MeilisearchSearcher implements SearcherInterface
{
    private readonly Marshaller $marshaller;

    public function __construct(
        private readonly Client $client,
    ) {
        $this->marshaller = new Marshaller();
    }

    public function search(Search $search): Result
    {
        // optimized single document query
        if (
            1 === \count($search->indexes)
            && 1 === \count($search->filters)
            && $search->filters[0] instanceof Condition\IdentifierCondition
            && 0 === $search->offset
            && 1 === $search->limit
        ) {
            try {
                $data = $this->client->index($search->indexes[\array_key_first($search->indexes)]->name)->getDocument($search->filters[0]->identifier);
            } catch (ApiException $e) {
                if (404 !== $e->httpStatus) {
                    throw $e;
                }

                return new Result(
                    $this->hitsToDocuments($search->indexes, []),
                    0,
                );
            }

            return new Result(
                $this->hitsToDocuments($search->indexes, [$data]),
                1,
            );
        }

        if (1 !== \count($search->indexes)) {
            throw new \RuntimeException('Meilisearch does not yet support search multiple indexes: https://github.com/schranz-search/schranz-search/issues/28');
        }

        $index = $search->indexes[\array_key_first($search->indexes)];
        $searchIndex = $this->client->index($index->name);

        $query = null;
        $filters = [];
        foreach ($search->filters as $filter) {
            match (true) {
                $filter instanceof Condition\IdentifierCondition => $filters[] = $index->getIdentifierField()->name . ' = ' . $this->escapeFieldValue($index, $filter),
                $filter instanceof Condition\SearchCondition => $query = $filter->query,
                $filter instanceof Condition\EqualCondition => $filters[] = $filter->field . ' = ' . $this->escapeFieldValue($index, $filter),
                $filter instanceof Condition\NotEqualCondition => $filters[] = $filter->field . ' != ' . $this->escapeFieldValue($index, $filter),
                $filter instanceof Condition\GreaterThanCondition => $filters[] = $filter->field . ' > ' . $filter->value, // TODO escape?
                $filter instanceof Condition\GreaterThanEqualCondition => $filters[] = $filter->field . ' >= ' . $filter->value, // TODO escape?
                $filter instanceof Condition\LessThanCondition => $filters[] = $filter->field . ' < ' . $filter->value, // TODO escape?
                $filter instanceof Condition\LessThanEqualCondition => $filters[] = $filter->field . ' <= ' . $filter->value, // TODO escape?
                default => throw new \LogicException($filter::class . ' filter not implemented.'),
            };
        }

        $searchParams = [];
        if ([] !== $filters) {
            $searchParams = ['filter' => \implode(' AND ', $filters)];
        }

        if (0 !== $search->offset) {
            $searchParams['offset'] = $search->offset;
        }

        if ($search->limit) {
            $searchParams['limit'] = $search->limit;
        }

        foreach ($search->sortBys as $field => $direction) {
            $searchParams['sort'][] = $field . ':' . $direction;
        }

        $data = $searchIndex->search($query, $searchParams)->toArray();

        return new Result(
            $this->hitsToDocuments($search->indexes, $data['hits']),
            $data['totalHits'] ?? $data['estimatedTotalHits'] ?? null,
        );
    }

    /**
     * @param Index[] $indexes
     * @param iterable<array<string, mixed>> $hits
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function hitsToDocuments(array $indexes, iterable $hits): \Generator
    {
        $index = $indexes[\array_key_first($indexes)];

        foreach ($hits as $hit) {
            yield $this->marshaller->unmarshall($index->fields, $hit);
        }
    }

    public function escapeFieldValue(Index $index, object $field): string|int|bool|float
    {
        $value = match(true) {
            $field instanceof Condition\IdentifierCondition => $field->identifier,
            default => $field->value,
        };

        try {
            // Instead of guessing the type of the field, we use the type of the indexed field.
            $indexedField = $index->getFieldByPath($field->field);
        } catch (FieldByPathNotFoundException) {
            return $value;
        }

        // Not every field type needs to be escaped.
        return match(true) {
            $indexedField instanceof TextField, $indexedField instanceof IdentifierField => '"' . $value . '"',
            $indexedField instanceof BooleanField => $value ? 'true' : 'false',
            default => $value,
        };
    }
}
