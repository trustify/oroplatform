<?php

namespace Oro\Bundle\DataGridBundle\Extension\Totals;

use Doctrine\ORM\Query\Expr\GroupBy;
use Doctrine\ORM\Query\Expr\Select;
use Doctrine\ORM\QueryBuilder;

use Oro\Bundle\DataGridBundle\Datagrid\Builder;
use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Datagrid\Common\MetadataObject;
use Oro\Bundle\DataGridBundle\Datagrid\Common\ResultsObject;
use Oro\Bundle\DataGridBundle\Datagrid\RequestParameters;
use Oro\Bundle\DataGridBundle\Datasource\DatasourceInterface;
use Oro\Bundle\DataGridBundle\Extension\AbstractExtension;
use Oro\Bundle\DataGridBundle\Extension\Formatter\Property\PropertyInterface;

use Oro\Bundle\LocaleBundle\Formatter\DateTimeFormatter;
use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;

use Oro\Bundle\TranslationBundle\Translation\Translator;

class OrmTotalsExtension extends AbstractExtension
{
    /** @var RequestParameters */
    protected $requestParams;

    /** @var  Translator */
    protected $translator;

    /** @var QueryBuilder */
    protected $masterQB;

    /** @var NumberFormatter */
    protected $numberFormatter;

    /** @var DateTimeFormatter */
    protected $dateTimeFormatter;

    public function __construct(
        RequestParameters $requestParams = null,
        Translator $translator,
        NumberFormatter $numberFormatter,
        DateTimeFormatter $dateTimeFormatter
    ) {
        $this->requestParams     = $requestParams;
        $this->translator        = $translator;
        $this->numberFormatter   = $numberFormatter;
        $this->dateTimeFormatter = $dateTimeFormatter;
    }

    /**
     * {@inheritDoc}
     */
    public function isApplicable(DatagridConfiguration $config)
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function processConfigs(DatagridConfiguration $config)
    {
        $this->validateConfiguration(
            new Configuration(),
            ['totals' => $config->offsetGetByPath(Configuration::TOTALS_PATH)]
        );
    }

    /**
     * {@inheritDoc}
     */
    public function visitDatasource(DatagridConfiguration $config, DatasourceInterface $datasource)
    {
        $this->masterQB = clone $datasource->getQueryBuilder();
    }

    /**
     * {@inheritDoc}
     */
    public function visitResult(DatagridConfiguration $config, ResultsObject $result)
    {
        $rootIdentifier = [];

        $totals = $config->offsetGetByPath(Configuration::COLUMNS_PATH);
        if (null != $totals) {
            $totalQueries = [];
            foreach ($totals as $field => $total) {
                if (isset($total['query'])) {
                    $totalQueries[] = $total['query'] . ' AS ' . $field;
                }
            };

            $groupParts   = [];
            $groupByParts = $this->masterQB->getDQLPart('groupBy');
            if (!empty($groupByParts)) {
                /** @var GroupBy $groupByPart */
                foreach ($groupByParts as $groupByPart) {
                    $groupParts = array_merge($groupParts, $groupByPart->getParts());
                }
            }

            if (empty($groupParts)) {
                $rootIdentifiers = $this->masterQB->getEntityManager()
                    ->getClassMetadata($this->masterQB->getRootEntities()[0])->getIdentifier();
                $rootAlias = $this->masterQB->getRootAliases()[0];
                foreach ($rootIdentifiers as $field) {
                    $rootIdentifier[] = [
                        'fieldAlias'  => $field,
                        'alias'       => $field,
                        'entityAlias' => $rootAlias
                    ];
                }
            } else {
                foreach ($groupParts as $groupPart) {
                    if (strpos($groupPart, '.')) {
                        list($rootAlias, $rootIdentifierPart) = explode('.', $groupPart);
                        $rootIdentifier[] = [
                            'fieldAlias'  => $rootIdentifierPart,
                            'entityAlias' => $rootAlias,
                            'alias'       => $rootIdentifierPart
                        ];
                    } else {
                        $selectParts = $this->masterQB->getDQLPart('select');
                        /** @var Select $selectPart */
                        foreach ($selectParts as $selectPart) {
                            foreach ($selectPart->getParts() as $part) {
                                if (preg_match('/^(.*)\sas\s(.*)$/i', $part, $matches)) {
                                    if (count($matches) == 3 && $groupPart == $matches[2]) {
                                        $rootIdentifier[] = [
                                            'fieldAlias' => $matches[1],
                                            'alias'      => $matches[2]
                                        ];
                                    }
                                } else {
                                    $rootIdentifier[] = [
                                        'fieldAlias' => $groupPart,
                                        'alias'      => $groupPart
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            $dataQueryBuilder = $this->masterQB
                ->select($totalQueries)
                ->resetDQLPart('groupBy');

            foreach ($rootIdentifier as $identifier) {
                $ids = [];
                foreach ($result['data'] as $res) {
                    $ids[] = $res[$identifier['alias']];
                }

                $dataQueryBuilder->andWhere(
                    $this->masterQB->expr()->in(
                        isset($identifier['entityAlias'])
                            ? $identifier['entityAlias'] . '.' . $identifier['fieldAlias']
                            : $identifier['fieldAlias']
                        ,
                        $ids
                    )
                );
            }

            $data = $dataQueryBuilder
                ->getQuery()
                ->setFirstResult(null)
                ->setMaxResults(null)
                ->getScalarResult();

            if (!empty($data)) {
                foreach ($totals as $field => &$total) {
                    if (isset($data[0][$field])) {
                        $total['total'] = isset($total[Configuration::TOTALS_FORMATTER])
                            ? $this->applyFrontendFormatting($data[0][$field], $total[Configuration::TOTALS_FORMATTER])
                            : $data[0][$field];
                    }
                    if (isset($total['label'])) {
                        $total['label'] = $this->translator->trans($total['label']);
                    }
                };
            }
        }

        $result->offsetAddToArray('options', ['totals' => $totals ? : []]);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function visitMetadata(DatagridConfiguration $config, MetadataObject $metaData)
    {
        $totals = $config->offsetGetByPath(Configuration::COLUMNS_PATH);
        $metaData
            ->offsetAddToArray('state', ['totals' => $totals])
            ->offsetAddToArray(MetadataObject::REQUIRED_MODULES_KEY, ['oro/datagrid/totals-builder']);
    }

    /**
     * {@inheritDoc}
     */
    public function getPriority()
    {
        // should visit after all extensions
        return -250;
    }

    /**
     * @param mixed|null $val
     * @param string|null $formatter
     * @return string|null
     */
    protected function applyFrontendFormatting($val = null, $formatter = null)
    {
        if (null != $formatter) {
            switch ($formatter) {
                case PropertyInterface::TYPE_DATE:
                    $val = $this->dateTimeFormatter->formatDate($val);
                    break;
                case PropertyInterface::TYPE_DATETIME:
                    $val = $this->dateTimeFormatter->format($val);
                    break;
                case PropertyInterface::TYPE_DECIMAL:
                    $val = $this->numberFormatter->formatDecimal($val);
                    break;
                case PropertyInterface::TYPE_INTEGER:
                    $val = $this->numberFormatter->formatDecimal($val);
                    break;
                case PropertyInterface::TYPE_PERCENT:
                    $val = $this->numberFormatter->formatPercent($val);
                    break;
            }
        }

        return $val;
    }
}
