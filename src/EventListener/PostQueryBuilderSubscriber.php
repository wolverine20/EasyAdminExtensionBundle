<?php

namespace AlterPHP\EasyAdminExtensionBundle\EventListener;

use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Session\Session;
/**
 * Apply filters on list/search queryBuilder.
 */
class PostQueryBuilderSubscriber implements EventSubscriberInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            EasyAdminEvents::POST_LIST_QUERY_BUILDER => array('onPostListQueryBuilder'),
            EasyAdminEvents::POST_SEARCH_QUERY_BUILDER => array('onPostSearchQueryBuilder'),
        );
    }

    /**
     * Called on POST_LIST_QUERY_BUILDER event.
     *
     * @param GenericEvent $event
     */
    public function onPostListQueryBuilder(GenericEvent $event)
    {
        $queryBuilder = $event->getArgument('query_builder');
        $session = new Session();
        if ($event->hasArgument('request')) {
            $this->applyRequestFilters($queryBuilder, $event->getArgument('request')->get('filters', array()));
            $this->applyFormFilters($queryBuilder, $event->getArgument('request')->get('form_filters', array()));
        }

        if($event->getArgument('request')->query->get('entity') == 'Expediente'){
            $queryBuilderExcel = clone $queryBuilder;
            $ids_exp = $queryBuilderExcel->select('
            entity.id as id,
            entity.updated as updated
            ')
            ->orderBy('entity.updated')
            ->getQuery()->getScalarResult();
            $ids = array_column($ids_exp, 'id');
            $session->set('temp_query_builder',$ids);
        }
    }

    /**
     * Called on POST_SEARCH_QUERY_BUILDER event.
     *
     * @param GenericEvent $event
     */
    public function onPostSearchQueryBuilder(GenericEvent $event)
    {
        $queryBuilder = $event->getArgument('query_builder');

        if ($event->hasArgument('request')) {
            $this->applyRequestFilters($queryBuilder, $event->getArgument('request')->get('filters', array()));
        }
    }

    /**
     * Applies request filters on queryBuilder.
     *
     * @param QueryBuilder $queryBuilder
     * @param array        $filters
     */
    protected function applyRequestFilters(QueryBuilder $queryBuilder, array $filters = array())
    {
        foreach ($filters as $field => $value) {
            // Empty string and numeric keys is considered as "not applied filter"
            if (\is_int($field) || '' === $value) {
                continue;
            }
            
            // Add root entity alias if none provided
            $field = false === \strpos($field, '.') ? $queryBuilder->getRootAlias().'.'.$field : $field;
            // Checks if filter is directly appliable on queryBuilder
            if (!$this->isFilterAppliable($queryBuilder, $field)) {
                continue;
            }

            
            // Sanitize parameter name
            $parameter = 'request_filter_'.\str_replace('.', '_', $field);

            $this->filterQueryBuilder($queryBuilder, $field, $parameter, $value);
        }
    }

    /**
     * Applies form filters on queryBuilder.
     *
     * @param QueryBuilder $queryBuilder
     * @param array        $filters
     */
    protected function applyFormFilters(QueryBuilder $queryBuilder, array $filters = array())
    {
        
        foreach ($filters as $field => $value) {

           
       
            $value = $this->filterEasyadminAutocompleteValue($value);
            // Empty string and numeric keys is considered as "not applied filter"
            if (\is_int($field) || '' === $value) {
                continue;
            }

            if($field == 'busquedaAbogadoAsignado'){
               
                $queryBuilder->join('eu.user','usuario')
                            ->andWhere('( usuario.nombre like :campo or usuario.apellido like :campo or usuario.matricula like :campo or usuario.documento like :campo )')
                            ->setParameter('campo',"%".$value."%");
            }else{
                // Add root entity alias if none provided
                $field = false === \strpos($field, '.') ? $queryBuilder->getRootAlias().'.'.$field : $field;
                            
                // Checks if filter is directly appliable on queryBuilder
                if (!$this->isFilterAppliable($queryBuilder, $field)) {
                    continue;
                }
                // Sanitize parameter name
                $parameter = 'form_filter_'.\str_replace('.', '_', $field);

                $this->filterQueryBuilder($queryBuilder, $field, $parameter, $value);
            }
          
        }
      
    }

    private function filterEasyadminAutocompleteValue($value)
    {
        if (!\is_array($value) || !isset($value['autocomplete']) || 1 !== \count($value)) {
            return $value;
        }

        return $value['autocomplete'];
    }

    /**
     * Filters queryBuilder.
     *
     * @param QueryBuilder $queryBuilder
     * @param string       $field
     * @param string       $parameter
     * @param mixed        $value
     */
    protected function filterQueryBuilder(QueryBuilder $queryBuilder, string $field, string $parameter, $value)
    {   
        $bandera=false;
        // For multiple value, use an IN clause, equality otherwise
        if (\is_array($value)) {
            $filterDqlPart = $field.' IN (:'.$parameter.')';
        } elseif ('_NULL' === $value) {
            $parameter = null;
            $filterDqlPart = $field.' IS NULL';
        } elseif ('_NOT_NULL' === $value) {
            $parameter = null;
            $filterDqlPart = $field.' IS NOT NULL';
        }elseif($field=='entity.abogado' || $field=='entity.demandada' ||$field=='entity.actora'){
            $filterDqlPart = $field." LIKE '%".$value."%'"; 
            $bandera=true;
        }elseif($field=='entity.fechaDespacho'){
            $filterDqlPart = $field." >= :".$parameter.""; 
        }elseif($field=='entity.updated'){
            $filterDqlPart = 'entity.fechaDespacho'." <= :".$parameter.""; 
        }else {
            $filterDqlPart = $field.' = :'.$parameter;
        }

        $queryBuilder->andWhere($filterDqlPart);
        if (null !== $parameter && !$bandera) {
            $queryBuilder->setParameter($parameter, $value);
        }
 
    }

    /**
     * Checks if filter is directly appliable on queryBuilder.
     *
     * @param QueryBuilder $queryBuilder
     * @param string       $field
     *
     * @return bool
     */
    protected function isFilterAppliable(QueryBuilder $queryBuilder, string $field): bool
    {
        $qbClone = clone $queryBuilder;

        try {
            $qbClone->andWhere($field.' IS NULL');

            // Generating SQL throws a QueryException if using wrong field/association
            $qbClone->getQuery()->getSQL();
        } catch (QueryException $e) {
            return false;
        }

        return true;
    }
}
