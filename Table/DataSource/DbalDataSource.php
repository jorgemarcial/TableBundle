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

use DateTime;
use JGM\TableBundle\Table\Filter\EntityFilter;
use JGM\TableBundle\Table\Filter\FilterInterface;
use JGM\TableBundle\Table\Filter\FilterOperator;
use JGM\TableBundle\Table\Order\Model\Order;
use JGM\TableBundle\Table\Pagination\Model\Pagination;
use JGM\TableBundle\Table\Utils\ReflectionHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * DataSource, which deals arrays.
 * 
 * @author	Jorge Marcial <jorgemarcial.alvarez@gmail.com>
 * @since	1.3
 */
class DbalDataSource implements DataSourceInterface
{
	/**
	 * Array with the parts of query, fields and body.
	 * Example :
	 * $sqlStatementParts['where'] = true | false flag if where clause exist
	 * $sqlStatementParts['fields'] = 'id.table1, name.table1'
	 * $sqlStatementParts['body'] = 'SELECT {fields}  FROM `table1`'
	 *
	 * @var array
	 */
	protected $sqlStatementParts;

	/**
	 * Array with filter functions.
	 * 
	 * @var array
	 */
	protected $filterFunctions;
	
	public function __construct($sqlStatementParts)
	{
		$this->sqlStatementParts = $sqlStatementParts;

		$this->filterFunctions = array(
			FilterOperator::EQ			=> function($item, $filter) { return $item == $filter; },
			FilterOperator::NOT_EQ		=> function($item, $filter) { return $item != $filter; },
			FilterOperator::GEQ			=> function($item, $filter) { return $item >= $filter; },
			FilterOperator::GT			=> function($item, $filter) { return $item > $filter; },
			FilterOperator::LEQ			=> function($item, $filter) { return $item <= $filter; },
			FilterOperator::LT			=> function($item, $filter) { return $item < $filter; },
			FilterOperator::LIKE		=> function($item, $filter) { return strpos(strtolower($item), strtolower($filter)) !== false; },
			FilterOperator::NOT_LIKE	=> function($item, $filter) { return strpos(strtolower($item), strtolower($filter)) === false; },
		);
	}
	
	public function getData(ContainerInterface $container, array $columns, array $filters = null, Pagination $pagination = null, Order $sortable = null)
	{
		$query = $this->joinQuery();

		$query = $this->applyFilters($query, $filters);

		if($sortable !== null)
		{
			$sortableColumn = $columns[$sortable->getCurrentColumnName()];
			$sortableColumnInfo = $sortableColumn->getInfo();
			$orderByStmt = " ORDER BY ".$sortableColumnInfo['rootAlias'].".".$sortable->getCurrentColumnName()." ".$sortable->getCurrentDirection();
			$query .= $orderByStmt;
		}

		if($pagination !== null)
		{
			$firstResult = $pagination->getCurrentPage() * $pagination->getItemsPerRow();
			$paginatorStmt = " LIMIT ".$pagination->getItemsPerRow()." OFFSET ".$firstResult;
			$query .= $paginatorStmt;
		}


		$stmt = $container->get('doctrine.orm.entity_manager')->getConnection()->prepare($query);
		$stmt->execute();
		
		return $stmt->fetchAll();
	}
	
	public function getCountItems(ContainerInterface $container, array $columns, array $filters = null)
	{
		$countQuery = $this->applyFilters($this->sqlStatementParts['body'], $filters);

		try
		{
			// get first Column necessary attributes also could use reset function but reset pointer.
			foreach ($columns as $column) {
				$columnInfo = $column->getInfo();
				$columnName = $column->getName();
				break;
			}

			// @todo just working for sentences has all fields like
			// SELECT * FROM TABLE; replace first * char in the statement by count()
			$countStmt=sprintf('count('.$columnInfo['rootAlias'].'.%s) alias',$columnName);
			$countQuery = preg_replace("/{fields}/", $countStmt, $countQuery, 1);

			$stmt = $container->get('doctrine.orm.entity_manager')->getConnection()->prepare($countQuery);
			$stmt->execute();
			$result =  $stmt->fetch();

			return $result['alias'];
		}
		catch (NoResultException $ex)
		{
			$result = 0;
		}
		
		return $result;
	}

	public function getType()
	{
		return 'dbal';
	}

	/**
	 * Applys the filters to the query and sets required parameters.
	 *
	 * @param String $query
	 * @param array $filters Array with filters.
	 */
	protected function applyFilters($query, array $filters = array())
	{
		if(count($filters) < 1)
		{
			return;
		}

		$findWhere = false;
		$whereParts = array();

		foreach($filters as $filter)
		{
			// Only apply used filters to the query
			if($filter->isActive() !== true)
			{
				continue;
			}

			foreach($filter->getColumns() as $column)
			{
				//@todo more filters just working for TextFilter Type
				if($filter->getOperator() === FilterOperator::LIKE || $filter->getOperator() === FilterOperator::NOT_LIKE)
				{
					if (isset($this->sqlStatementParts['whereClause']) || $findWhere == true) {
						$clause = 'AND ';
					} else {
						$clause = 'WHERE ';
						$findWhere = true;
					}

					$filterInfo = $filter->getInfo();
					$whereParts [] = 'AND '.$filterInfo['rootAlias'].'.'.$filter->getName() . ' LIKE "%' . strtolower($filter->getValue()) . '%"';
				}
			}
		}

		// If there was more than one filter used, add them all to the query builder.
		if(count($whereParts) > 0)
		{
			$whereStatement = implode($whereParts);

			return $query.' '.$whereStatement;
		}

		return $query;
	}

	public function joinQuery(){
		$stmt = $this->sqlStatementParts;
		$joinQuery = preg_replace('/{fields}/', $stmt['fields'], $stmt['body']);

		return $joinQuery;
	}
}