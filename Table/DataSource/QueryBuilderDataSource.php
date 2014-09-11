<?php

namespace PZAD\TableBundle\Table\DataSource;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use PZAD\TableBundle\Table\Column\ColumnInterface;
use PZAD\TableBundle\Table\Filter\FilterInterface;
use PZAD\TableBundle\Table\Filter\FilterOperator;
use PZAD\TableBundle\Table\Model\PaginationOptionsContainer;
use PZAD\TableBundle\Table\Model\SortableOptionsContainer;
use PZAD\TableBundle\Table\TableException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DataSource implementation for fetching the data
 * from a database by executing a query builder.
 *
 * @author	Jan Mühlig <mail@janmuehlig.de>
 * @since	1.0.0
 */
class QueryBuilderDataSource implements DataSourceInterface
{
	/**
	 * @var string
	 */
	protected $entity;
	
	/**
	 * @var QueryBuilder
	 */
	protected $queryBuilder;

	public function __construct(QueryBuilder $queryBuilder = null)
	{
		$this->queryBuilder = $queryBuilder;
	}
	
	public function getData(ContainerInterface $container, array $columns, array $filters = null, PaginationOptionsContainer $pagination = null, SortableOptionsContainer $sortable = null)
	{
		if($this->queryBuilder === null)
		{
			TableException::noQueryBuilder();
		}
		
		$queryBuilder = clone $this->queryBuilder;
		
		$this->applyFilters($container->get('request'), $queryBuilder, $filters);
		
		$aliases = $queryBuilder->getRootAliases();
		
		if($sortable !== null)
		{
			$queryBuilder->orderBy(sprintf('%s.%s', $aliases[0], $sortable->getColumnName()), $sortable->getDirection());
		}
		
		if($pagination !== null)
		{
			$countPages = $this->getCountPages($container, $columns, $filters, $pagination);
			if(	$pagination->getCurrentPage() < 0
				|| $pagination->getCurrentPage() > $countPages
			)
			{
				
				print_r($countPages);
				throw new NotFoundHttpException(sprintf("%s < 0 || %s > %s", $pagination->getCurrentPage(), $pagination->getCurrentPage(), $countPages));
			}
			
			$queryBuilder->setFirstResult($pagination->getCurrentPage() * $pagination->getItemsPerRow());
			$queryBuilder->setMaxResults($pagination->getItemsPerRow());
			
			return new Paginator($queryBuilder->getQuery(), false);
		}
		
		return $queryBuilder->getQuery()->getResult();
	}
	
	public function getCountPages(ContainerInterface $container, array $columns, array $filters = null, PaginationOptionsContainer $pagination = null)
	{
		if($this->queryBuilder === null)
		{
			TableException::noQueryBuilder();
		}
		
		$queryBuilder = clone $this->queryBuilder;
		
		$aliases = $queryBuilder->getRootAliases();
		
		$queryBuilder->select(sprintf('count(%s)', $aliases[0]));
		
		$this->applyFilters($container->get('request'), $queryBuilder, $filters);
		
		$countItems = $queryBuilder->getQuery()->getSingleScalarResult();
		$countPages = ceil($countItems / $pagination->getItemsPerRow());
		
		return $countPages < 1 ? 1 : $countPages;
	}
	
	/**
	 * Applys the filters to the query builder and sets required parameters.
	 * 
	 * @param Request $request				The http request.
	 * @param QueryBuilder $queryBuilder	The query builder.
	 * @param array $filters				Array with filters.
	 */
	protected function applyFilters(Request $request, QueryBuilder $queryBuilder, array $filters = array())
	{
		if(count($filters) < 1)
		{
			return;
		}
		
		$whereParts = array();
		
		foreach($filters as $filter)
		{
			/* @var $filter FilterInterface */
			
			$filterValue = $request->attributes->get($filter->getName());
			if($filterValue === null || trim($filterValue) === "")
			{
				continue;
			}
			
			foreach($filter->getColumns() as $column)
			{
				/* @var $column ColumnInterface */

				$whereParts[] = sprintf($this->createWherePart($filter->getOperator()), $column, $filter->getName());
			}
			
			if($filter->getOperator() === FilterOperator::LIKE || $filter->getOperator() === FilterOperator::NOT_LIKE)
			{
				$queryBuilder->setParameter($filter->getName(), '%' . $filterValue . '%');
			}
			else
			{
				$queryBuilder->setParameter($filter->getName(), $filterValue);
			}
		}
		
		if(count($whereParts) > 0)
		{
			$whereStatement = implode(' and ', $whereParts);

			if(strpos(strtolower($queryBuilder->getDQL()), 'where') === false)
			{
				$queryBuilder->where($whereStatement);
			}
			else
			{
				$queryBuilder->andWhere($whereStatement);
			}
		}
	}
	
	/**
	 * Creates a where part with placeholders, like '${column} <= ${parameter}' for operator 'LT'.
	 * 
	 * @param int $filterOperator	Operator of the filter.
	 * 
	 * @return string				Where part.
	 */
	protected function createWherePart($filterOperator)
	{
		if($filterOperator === FilterOperator::EQ)
		{
			return "%s = :%s";
		}
		else if($filterOperator === FilterOperator::NOT_EQ)
		{
			return "%s != :%s";
		}
		else if($filterOperator === FilterOperator::GT)
		{
			return "%s > :%s";
		}
		else if($filterOperator === FilterOperator::GEQ)
		{
			return "%s >= :%s";
		}
		else if($filterOperator === FilterOperator::LT)
		{
			return "%s < :%s";
		}
		else if($filterOperator === FilterOperator::LEQ)
		{
			return "%s <= :%s";
		}
		else if($filterOperator === FilterOperator::NOT_LIKE)
		{
			return "%s not like :%s";
		}
		else
		{
			return "%s like %s";
		}
	}
}
