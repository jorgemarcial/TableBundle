<?php

/*
 * This file is part of the TableBundle.
 *
 * (c) Jan Mühlig <mail@janmuehlig.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JGM\TableBundle\Table\DataSource;

use JGM\TableBundle\Table\DataSource\ContainerInterace;
use JGM\TableBundle\Table\DataSource\DataSourceInterface;
use JGM\TableBundle\Table\Model\SortableOptionsContainer;
use JGM\TableBundle\Table\Order\Model\Order;
use JGM\TableBundle\Table\Pagination\Model\Pagination;
use JGM\TableBundle\Table\TableException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * DataSource for the Mongo ODM.
 *
 * @author
 * @since	1.3
 */
class MongoQueryBuilderDataSource implements DataSourceInterface
{
    private $queryBuilder;
    protected $counter;

    public function __construct($queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    public function getData(
        ContainerInterface $container,
        array $columns,
        array $filters = null,
        Pagination $pagination = null,
        Order $sortable = null
    )
    {
        $queryBuilder = clone $this->queryBuilder;

        if($sortable !== null)
        {
            $queryBuilder->sort($sortable->getCurrentColumnName(), $sortable->getCurrentDirection());
        }

        return $queryBuilder->getQuery()->execute();
    }

    public function getCountItems(
        ContainerInterface $container,
        array $columns,
        array $filters = null
    )
    {
        $queryBuilder = clone $this->queryBuilder;
        $counter = $queryBuilder->getQuery()->execute()->count();

        return $queryBuilder->getQuery()->execute()->count();
    }

    public function getType()
    {
        return 'mongo';
    }
}
