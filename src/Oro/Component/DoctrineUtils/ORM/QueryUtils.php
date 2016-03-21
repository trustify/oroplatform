<?php

namespace Oro\Component\DoctrineUtils\ORM;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\SQLParserUtils;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\QueryBuilder;

use Oro\Component\PhpUtils\QueryUtil;

class QueryUtils
{
    /**
     * @param Query $query
     * @param array $paramMappings
     *
     * @return array
     *
     * @throws QueryException
     */
    public static function processParameterMappings(Query $query, $paramMappings)
    {
        $sqlParams = [];
        $types     = [];

        /** @var Query\Parameter $parameter */
        foreach ($query->getParameters() as $parameter) {
            $key = $parameter->getName();

            if (!isset($paramMappings[$key])) {
                throw QueryException::unknownParameter($key);
            }

            $value = $query->processParameterValue($parameter->getValue());
            $type  = ($parameter->getValue() === $value)
                ? $parameter->getType()
                : Query\ParameterTypeInferer::inferType($value);

            foreach ($paramMappings[$key] as $position) {
                $types[$position] = $type;
            }

            $sqlPositions = $paramMappings[$key];
            $value        = [$value];
            $countValue   = count($value);

            for ($i = 0, $l = count($sqlPositions); $i < $l; $i++) {
                $sqlParams[$sqlPositions[$i]] = $value[($i % $countValue)];
            }
        }

        if (count($sqlParams) !== count($types)) {
            throw QueryException::parameterTypeMismatch();
        }

        if ($sqlParams) {
            ksort($sqlParams);
            $sqlParams = array_values($sqlParams);

            ksort($types);
            $types = array_values($types);
        }

        return [$sqlParams, $types];
    }

    /**
     * @param ResultSetMapping $mapping
     * @param string           $alias
     *
     * @return string
     *
     * @throws QueryException
     */
    public static function getColumnNameByAlias(ResultSetMapping $mapping, $alias)
    {
        foreach ($mapping->scalarMappings as $key => $val) {
            if ($alias === $val) {
                return $key;
            }
        }

        throw new QueryException(sprintf('Unknown column alias: %s', $alias));
    }

    /**
     * Returns an expression in SELECT clause by its alias
     *
     * @param QueryBuilder $qb
     * @param string       $alias An alias of an expression in SELECT clause
     *
     * @return string|null
     */
    public static function getSelectExprByAlias(QueryBuilder $qb, $alias)
    {
        /** @var \Doctrine\ORM\Query\Expr\Select $selectPart */
        foreach ($qb->getDQLPart('select') as $selectPart) {
            foreach ($selectPart->getParts() as $part) {
                if (preg_match_all('#(\,\s*)*(?P<expr>.+?)\\s+AS\\s+(?P<alias>\\w+)#i', $part, $matches)) {
                    foreach ($matches['alias'] as $key => $val) {
                        if ($val === $alias) {
                            return $matches['expr'][$key];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param Query $query
     *
     * @return string
     *
     * @throws QueryException
     */
    public static function getExecutableSql(Query $query)
    {
        $parserResult = static::parseQuery($query);

        $sql = $parserResult->getSqlExecutor()->getSqlStatements();

        list($params, $types) = self::processParameterMappings($query, $parserResult->getParameterMappings());
        list($sql, $params, $types) = SQLParserUtils::expandListParameters($sql, $params, $types);

        $paramPos = SQLParserUtils::getPlaceholderPositions($sql);
        for ($i = count($paramPos) - 1; $i >= 0; $i--) {
            $sql = substr_replace(
                $sql,
                $query->getEntityManager()->getConnection()->quote($params[$i], $types[$i]),
                $paramPos[$i],
                1
            );
        }

        return $sql;
    }

    /**
     * @param Query $query
     *
     * @return Query\ParserResult
     */
    public static function parseQuery(Query $query)
    {
        $parser = new Query\Parser($query);

        return $parser->parse();
    }

    /**
     * Builds CONCAT(...) DQL expression
     *
     * @param string[] $parts
     *
     * @return string
     */
    public static function buildConcatExpr(array $parts)
    {
        $stack = [];
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $stack[] = count($stack) === 0
                ? $parts[$i]
                : sprintf('CONCAT(%s, %s)', $parts[$i], array_pop($stack));
        }

        if (empty($stack)) {
            return '';
        }

        return array_pop($stack);
    }

    /**
     * Gets the root entity alias of the query.
     *
     * @param QueryBuilder $qb             The query builder
     * @param bool         $throwException Whether to throw exception in case the query does not have a root alias
     *
     * @return string|null
     *
     * @throws QueryException
     */
    public static function getSingleRootAlias(QueryBuilder $qb, $throwException = true)
    {
        $rootAliases = $qb->getRootAliases();

        $result = null;
        if (count($rootAliases) !== 1) {
            if ($throwException) {
                $errorReason = count($rootAliases) === 0
                    ? 'the query has no any root aliases'
                    : sprintf('the query has several root aliases. "%s"', implode(', ', $rootAliases));

                throw new QueryException(
                    sprintf(
                        'Can\'t get single root alias for the given query. Reason: %s.',
                        $errorReason
                    )
                );
            }
        } else {
            $result = $rootAliases[0];
        }

        return $result;
    }

    /**
     * Calculates the page offset
     *
     * @param int $page  The page number
     * @param int $limit The maximum number of items per page
     *
     * @return int
     */
    public static function getPageOffset($page, $limit)
    {
        return $page > 0
            ? ($page - 1) * $limit
            : 0;
    }

    /**
     * Applies the given joins for the query builder
     *
     * @param QueryBuilder $qb
     * @param array|null   $joins
     */
    public static function applyJoins(QueryBuilder $qb, $joins)
    {
        if (empty($joins)) {
            return;
        }

        $qb->distinct(true);
        $rootAlias = self::getSingleRootAlias($qb);
        foreach ($joins as $key => $val) {
            if (empty($val)) {
                $qb->leftJoin($rootAlias . '.' . $key, $key);
            } elseif (is_array($val)) {
                if (isset($val['join'])) {
                    $join = $val['join'];
                    if (false === strpos($join, '.')) {
                        $join = $rootAlias . '.' . $join;
                    }
                } else {
                    $join = $rootAlias . '.' . $key;
                }
                $condition     = null;
                $conditionType = null;
                if (isset($val['condition'])) {
                    $condition     = $val['condition'];
                    $conditionType = Expr\Join::WITH;
                }
                if (isset($val['conditionType'])) {
                    $conditionType = $val['conditionType'];
                }
                $qb->leftJoin($join, $key, $conditionType, $condition);
            } else {
                $qb->leftJoin($rootAlias . '.' . $val, $val);
            }
        }
    }

    /**
     * Checks the given criteria and converts them to Criteria object if needed
     *
     * @param Criteria|array|null $criteria
     *
     * @return Criteria
     */
    public static function normalizeCriteria($criteria)
    {
        if (null === $criteria) {
            $criteria = new Criteria();
        } elseif (is_array($criteria)) {
            $newCriteria = new Criteria();
            foreach ($criteria as $fieldName => $value) {
                $newCriteria->andWhere(Criteria::expr()->eq($fieldName, $value));
            }

            $criteria = $newCriteria;
        }

        return $criteria;
    }

    /**
     * @param QueryBuilder $qb
     * @param string $field
     * @param int $values
     */
    public static function applyOptimizedIn(QueryBuilder $qb, $field, array $values)
    {
        $exprs = [];
        $optimizedValues = QueryUtil::optimizeIntValues($values);

        if ($optimizedValues[QueryUtil::IN]) {
            $inParam = QueryUtil::generateParameterName('buid');

            $qb->setParameter($inParam, $optimizedValues[QueryUtil::IN]);
            $exprs[] = $qb->expr()->in($field, ':'.$inParam);
        }

        foreach ($optimizedValues[QueryUtil::IN_BETWEEN] as $range) {
            list($min, $max) = $range;
            $minParam = QueryUtil::generateParameterName('buid');
            $maxParam = QueryUtil::generateParameterName('buid');

            $qb->setParameter($minParam, $min);
            $qb->setParameter($maxParam, $max);
            $exprs[] = $qb->expr()->between($field, ':'.$minParam, ':'.$maxParam);
        }

        if ($exprs) {
            $qb->andWhere(call_user_func_array([$qb->expr(), 'orX'], $exprs));
        }
    }
}
