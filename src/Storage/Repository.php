<?php
namespace Bolt\Storage;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\DBAL\Query\QueryBuilder;
use Bolt\Mapping\ClassMetadata;
use Bolt\Events\HydrationEvent;
use Bolt\Events\StorageEvent;
use Bolt\Events\StorageEvents;


/**
 * A default repository class that other repositories can inherit to provide more specific features.
 */
class Repository implements ObjectRepository
{
    
    public $em;
    public $_class;
    public $entityName;
    public $hydrator;
    public $persister;
    public $loader;
    
    /**
     * Initializes a new <tt>Repository</tt>.
     *
     * @param EntityManager         $em    The EntityManager to use.
     * @param Mapping\ClassMetadata $class The class descriptor.
     */
    public function __construct($em, ClassMetadata $classMetadata = null, $hydrator = null, $persister = null, $loader = null)
    {
        $this->em         = $em;
        if (null !== $classMetadata) {
            $this->_class     = $classMetadata;
            $this->entityName  = $classMetadata->getName();
        }
        
        if (null === $hydrator) {
            $this->setHydrator(new Hydrator($classMetadata));
        }
        
        if (null === $persister) {
            $this->setPersister(new Persister());
        }
        
        if (null === $loader) {
            $this->setLoader(new Loader());
        }
    }
    
    /**
     * Creates a new QueryBuilder instance that is prepopulated for this entity name.
     *
     * @param string $alias
     * @param string $indexBy The index for the from.
     *
     * @return QueryBuilder
     */
    public function createQueryBuilder($alias = null, $indexBy = null)
    {
        if (null === $alias) {
            $alias = $this->getAlias();
        }
        return $this->em->createQueryBuilder()
            ->select($alias.".*")
            ->from($this->getTableName(), $alias);
    }

    /**
     * Finds an object by its primary key / identifier.
     *
     * @param mixed $id The identifier.
     *
     * @return object The object.
     */
    public function find($id)
    {
        $qb = $this->getLoadQuery();
        $result = $qb->where($this->getAlias().'.id = :id')->setParameter('id', $id)->execute()->fetch();
        if ($result) {
            return $this->hydrate($result, $qb);
        }
        
        return false;        
    }

    /**
     * Finds all objects in the repository.
     *
     * @return array The objects.
     */
    public function findAll()
    {
        return $this->findBy(array());
    }

    /**
     * Finds objects by a set of criteria.
     *
     * Optionally sorting and limiting details can be passed. An implementation may throw
     * an UnexpectedValueException if certain values of the sorting or limiting details are
     * not supported.
     *
     * @param array      $criteria
     * @param array|null $orderBy
     * @param int|null   $limit
     * @param int|null   $offset
     *
     * @return array The objects.
     *
     * @throws \UnexpectedValueException
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
    {
        $qb = $this->findWithCriteria($criteria, $orderBy, $limit, $offset);
        $result = $qb->execute()->fetchAll();
        
        if ($result) {
            return $this->hydrateAll($result, $qb);
        }
        
        return false;
        
    }

    /**
     * Finds a single object by a set of criteria.
     *
     * @param array $criteria The criteria.
     *
     * @return object The object.
     */
    public function findOneBy(array $criteria, array $orderBy = null)
    {
        $qb = $this->findWithCriteria($criteria, $orderBy);
        $result = $qb->execute()->fetch();
        
        if ($result) {
            return $this->hydrate($result, $qb);
        }
        
        return false;
    }
    
    /**
     * Internal method to build a basic select, returns QB object.
     * 
     * @return QueryBuilder.
     */
    protected function findWithCriteria(array $criteria, array $orderBy = null, $limit = null, $offset = null)
    {
        $qb = $this->getLoadQuery();
        foreach ($criteria as $col=>$val) {
            $qb->andWhere($this->getAlias().".$col = :$col");
            $qb->setParameter(":$col", $val);
        }
        if ($orderBy) {
            $qb->orderBy($orderBy[0], $orderBy[1]);
        }
        if ($limit) {
            $qb->setMaxResults($limit);
        }
        if ($offset) {
            $qb->setFirstResult($offset);
        }
        return $qb;
    }
    
    /**
     * Internal method to initialise and return a QueryBuilder instance.
     * Note that the metadata fields will be passed the instance to modify where appropriate.
     * 
     * @return QueryBuilder.
     */
    protected function getLoadQuery()
    {
       $qb = $this->createQueryBuilder();
       $this->loader->load($qb, $this->getClassMetadata());
       
       return $qb; 
    }
    
    
    /**
     * Deletes a single object.
     *
     * @param object $$object The entity to delete.
     *
     * @return bool.
     */
    public function delete($entity)
    {
        $event = new StorageEvent($entity);
        $this->event()->dispatch(StorageEvents::PRE_DELETE, $event);
        $qb = $this->em->createQueryBuilder()
            ->delete($this->getTableName())
            ->where("id = :id")
            ->setParameter('id', $entity->getId());
        
        $response = $qb->execute();
        $event = new StorageEvent($entity);
        $this->event()->dispatch(StorageEvents::POST_DELETE, $event);
        
        return $response;
    }
    
    /**
     * Saves a single object.
     *
     * @param object $$object The entity to delete.
     *
     * @return bool.
     */
    public function save($entity)
    {
        $qb = $this->em->createQueryBuilder();
        
        try {
            $existing = $entity->getId();
        } catch (Exception $e) {
            $existing = false;
        }
        
        $event = new StorageEvent($entity, array('create' => $existing));
        $this->event()->dispatch(StorageEvents::PRE_SAVE, $event);
        
        if ($existing) {
            $response = $this->update($entity);
        } else {
            $response = $this->insert($entity);
        }
        
        $this->event()->dispatch(StorageEvents::POST_SAVE, $event);
        
        return $response;
                
    }
    
    /**
     * Saves a new object into the database.
     *
     * @param object $$object The entity to insert.
     *
     * @return bool.
     */
    public function insert($entity)
    {
        $qb = $this->em->createQueryBuilder();
        $qb->insert($this->getTableName());
        $this->persister->persist($qb, $entity, $this->getClassMetadata());
                
        return $qb->execute();
    }
    
    /**
     * Updates an object into the database.
     *
     * @param object $$object The entity to update.
     *
     * @return bool.
     */
    public function update($entity)
    {
        $qb = $this->em->createQueryBuilder();
        $qb->update($this->getTableName());
        $this->persister->persist($qb, $entity, $this->getClassMetadata());

        $qb->where('id = :id')
            ->setParameter('id', $entity->getId());
        
        return $qb->execute();
    }
    
    
    
    /**
     * Internal method to hydrate an Entity Object from fetched data.
     * 
     * @return mixed.
     */
    protected function hydrate(array $data, QueryBuilder $qb)
    {
        $preArgs = new HydrationEvent(
            $data, 
            array('entity'=>$this->getEntityName(), 'repository' => $this)
        );
        $this->event()->dispatch(StorageEvents::PRE_HYDRATE, $preArgs);
        
        $entity = $this->hydrator->hydrate($data, $qb, $this->em);
        
        $postArgs = new HydrationEvent(
            $entity, 
            array('data'=>$data, 'repository'=>$this)
        );
        $this->event()->dispatch(StorageEvents::POST_HYDRATE, $postArgs);
        
        return $entity;
    }
    
    /**
     * Internal method to hydrate an array of Entity Objects from fetched data.
     * 
     * @return mixed.
     */
    protected function hydrateAll(array $data, QueryBuilder $qb)
    {
        $rows = array();
        foreach ($data as $row) {
           $rows[] = $this->hydrate($row, $qb); 
        }
        
        return $rows;
    }
    
    /**
     * @return void
     */
    public function setHydrator($hydrator)
    {
        $this->hydrator = $hydrator;
    }
    
    /**
     * @return void
     */
    public function setPersister($persister)
    {
        $this->persister = $persister;
    }
    
    
    /**
     * @return void
     */
    public function setLoader($loader)
    {
        $this->loader = $loader;
    }


    
    /**
     * @return string
     */
    public function getEntityName()
    {
        return $this->entityName;
    }

    /**
     * @return string
     */
    public function getClassName()
    {
        return $this->getEntityName();
    }
    
    /**
     * 
     * @return string
     */
    public function getTableName()
    {
        return $this->getClassMetadata()->getTableName();
    }
    
    /**
     * 
     * @return string
     */
    public function getAlias()
    {
        return $this->getClassMetadata()->getAliasName();
    }


    /**
     * @return EntityManager
     */
    public function getEntityManager()
    {
        return $this->em;
    }
    
    /**
     * Getter for class metadata 
     *
     * @return ClassMetadata
     */
    public function getClassMetadata()
    {
        return $this->_class;
    }
    
    /**
     * Shortcut method to fetch the Event Manager
     * 
     * @return EventManager
     */
    public function event()
    {
        return $this->getEntityManager()->getEventManager();
    }
    
    
    

}
