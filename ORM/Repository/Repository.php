<?php
    namespace SebastianExtra\ORM\Repository;

    use \Exception;
    use \PDO;
    use \ReflectionClass;

    use SebastianExtra\ORM\EntityManager;
    use SebastianExtra\ORM\Entity\GenericEntity;

    use Sebastian\Core\Cache\CacheManager;
    use Sebastian\Core\Database\Query\Expression\Expression;
    use Sebastian\Core\Database\Query\Expression\ExpressionBuilder;
    use Sebastian\Core\Database\Query\Part\DirectionalJoin;
    use Sebastian\Core\Database\Query\Part\Join;
    use Sebastian\Core\Database\Query\QueryFactory;
    use Sebastian\Core\Exception\SebastianException;

    use Sebastian\Utility\Collection\Collection;
    use Sebastian\Utility\Configuration\Configuration;
    
    /**
     * Repository
     *
     * fetches and loads database information into objects defined by a .yaml file
     * 
     * @author Tyler <tyler@sbstn.ca>
     * @since Oct. 2015
     */
    class Repository {
        protected static $tag = "Repository";
        
        protected $cm;
        protected $config;
        protected $connection;
        protected $definition;
        protected $em;
        
        protected $entity;
        protected $entityClass;
        protected $genericEntity;

        protected $initialized = false;
        protected $reflection;
        protected $orc;
        
        public function __construct(EntityManager $entityManager, CacheManager $cacheManager = null, Logger $logger = null, Configuration $config = null, $entity = null) {
            $this->em = $entityManager;
            $this->cm = $cacheManager;
            //$this->logger = $logger;
            $this->connection = $entityManager->getConnection();

            if ($config == null) $config = new Configuration();
            $this->config = $config->extend([ 'use_reflection' => true ]);

            $this->entity = $entity;

            if ($this->entity) {
                $this->entityClass = $entityManager->getNamespacePath($this->entity) ?? GenericEntity::class;
            } else {
                $this->genericEntity = true;
                $this->entityClass = GenericEntity::class;
            }
            
            $this->orc = $entityManager->getObjectReferenceCache();
            $this->entityCache = $entityManager->getEntityCache();
            $this->init();
        }

        /**
         * classes can override/inject their own init before this one. 
         * The expectation is that they also call this init.
         * 
         * convenience class so the user doesn't have to worry about these things
         * only setting the class name (maybe they shouldn't even have to do that)
         * @return none
         */
        public function init() {
            if ($this->entityClass != null) {
                $this->definition = $this->em->getDefinition($this->entity);

                $this->table = $this->definition->get('table');
                $this->keys = $this->definition->get('keys');
                $this->fields = $this->definition->sub('fields');

                $this->joins = $this->em->computeJoinSets($this->entity);
                $this->aliases = $this->em->generateTableAliases($this->entity, $this->joins);
                $this->columns = $this->em->computeColumnSets($this->entity, $this->joins, $this->aliases);

                if ($this->entityClass === GenericEntity::class) {
                    $this->config->set('use_reflection', false);
                    $this->genericEntity = true;
                }

                if ($this->config->get('use_reflection', false)) {
                    $this->reflection = new ReflectionClass($this->entityClass);
                }
                
                $this->columnMap = $this->generateColumnMap();
                $this->fieldMap = $this->generateFieldMap();
            }

            $this->initialized = true;
        }

        /**
         * [build description]
         * @param  [type] $object [description]
         * @param  array  $params [description]
         * @return [type]         [description]
         */
        public function build($object = null, array $fields = []) {
            if (!$object) $object = new $this->entityClass();

            foreach ($fields as $field => $value) {
                $object = $this->setFieldValue($object, $field, $value);
            }

            return $object;
        }

        public function delete($object) {
            $qf = QueryFactory::getFactory();
            $ef = new ExpressionBuilder();

            $qf = $qf->delete()->from($this->getTable());

            $whereExpression = null;
            foreach ($this->keys as $key) {
                $column = $this->em->mapFieldToColumn($this->entity, $key);
                $value = $this->getFieldValue($object, $key);

                $qf->bind($key, $value);
                $expression = $ef->eq("{$column}", ":{$key}");

                $whereExpression = $whereExpression == null ? 
                        $expression : $ef->andExpr($whereExpression, $expression);
            }

            $qf = $qf->where($whereExpression);

            $query = $qf->getQuery();
            $result = $this->connection->execute($query, $query->getBinds());

            $key = $this->entityCache->generateKey($object);
            $this->entityCache->invalidate($key);
        }

        public function findOne($where = [], $options = []) {
            return $this->find($where, $options)[0] ?? null;
        }

        public function find($where = [], $options = []) {
            $where = $where ?: [];

            $em = $this->em; // convenience
            $qb = $em->getQueryBuilder();
            $ef = new ExpressionBuilder();

            $keys = array_map(function($field) use ($em) {
                return $em->mapFieldToColumn($this->entity, $field);
            }, $this->keys);

            $qb = $qb->select($keys)
                     ->from($this->getTable(), $this->aliases[0]);

            $expression = null;

            foreach ($where as $field => $param) {
                $tempExpr = null; // reset the temp expression

                if ($param instanceof Expression) {
                    $tempExpr = $param;
                } else {
                    if ($this->fields->has($field)) {
                        $column = $em->mapFieldToColumn($this->entity, $field);
                    } else {
                        $column = $field;
                        throw new Exception("field {$field} doesn't map to a column for {$this->entity}");
                    }

                    if (!is_array($param)) $params = [$param]; // naming 
                    else $params = $param; 

                    $expr = null;
                    $lhs = "{$this->aliases[0]}.{$column}";

                    foreach ($params as $index => $value) {
                        if (preg_match("/(!|>=?|<=?) ?(.+)/i", $value, $matches) >= 1) {
                            $operator = $matches[1];
                            $value = $matches[2];

                            if ($operator === '!') $operator = Expression::TYPE_NOT_EQUALS;

                            $qf->bind("{$column}_{$index}", $value);
                            $expr = $ef->compare($lhs, $operator, ":{$column}_{$index}");
                        } else {
                            if ($boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                                $expr = $ef->is($lhs, $boolValue ? "TRUE" : "FALSE");
                            } else {
                                $qf->bind("{$column}_{$index}", $value);
                                $expr = $ef->eq($lhs, ":{$column}_{$index}");
                            }
                        }

                        if ($tempExpr) $tempExpr = $ef->orExpr($tempExpr, $expr);
                        else $tempExpr = $expr;
                    }
                }

                if ($expression) $expression = $ef->andExpr($expression, $tempExpr);
                else $expression = $tempExpr;
            }

            if ($expression) $qb->where($expression);

            if ($options && count($options) != 0) {
                if (isset($options['limit'])) $qb->limit($options['limit']);
                if (isset($options['offset'])) $qb->offset($options['offset']);
                if (isset($options['orderBy'])) {
                    foreach ($options['orderBy'] as $field => $direction) {
                        $column = $this->em->mapFieldToColumn($this->entity, $field);
                        $qb->orderBy("{$this->aliases[0]}.{$column}", $direction);
                    }
                }
            }

            $query = $qb->getQuery();
            $result = $this->connection->execute($query, $query->getBinds(), $query->getBindTypes());
            $results = $result->fetchAll() ?: [];

            $localFields = $em->getLocalFields($this->entity);
            $skeletons = []; // list of final objects

            foreach ($results as $index => $row) {
                $params = [];
                foreach ($row as $key => $value) {
                    $params[$this->getColumnMap()[$key]] = $value;
                }

                $skeletons[] = $this->get($params);
            }

            return $skeletons;
        }

        /**
         * loads an object from the database based off a 
         * set of rule defined in an orm config file
         * @param array $params initial paramters to seed the object with
         * @return Entity a completed, possibly lazily loaded Entity
         */
        const JOIN_TYPE_FK = 0;
        const JOIN_TYPE_JOIN_TABLE = 1;
        public function get($params) {
            if (!is_array($params)) {
                if (count($this->keys) > 1) {
                    throw new SebastianException(
                        "Cannot use simplified method signature when entity has more than one primary key"
                    );
                }

                $params = [ $this->keys[0] => $params ];
            }

            $qb = $this->em->getQueryBuilder();
            $ef = new ExpressionBuilder();

            if (count(array_intersect($this->keys, array_keys($params))) != count($this->keys)) {
                $keys = implode(', ', $this->keys);
                throw new SebastianException("One of [{$keys}] must be provided for entity {$this->entity}", 500);
            }

            // check temp cache
            $skeleton = $this->build(null, $params);
            $orcKey = $this->orc->generateKey($skeleton);

            if ($this->orc->isCached($orcKey)) {
                return $this->orc->load($orcKey);
            } else {
                $this->orc->cache($orcKey, $skeleton);
            }

            // then check long term cache

            $cmKey = $this->entityCache->generateKey($skeleton);
            if ($this->entityCache->isCached($cmKey)) {
                return $this->entityCache->load($cmKey);
            }

            $qb = $qb->select($this->columns)
                     ->from($this->getTable(), $this->aliases[0]);

            foreach ($this->joins as $field => $join) {
                $fieldConfig = $this->fields->sub($field);

                if ($fieldConfig->has('target_entity')) {
                    $target = $fieldConfig->get('target_entity');
                    $mEntityConfig = $this->em->getDefinition($target);
                    $table = $mEntityConfig->get('table');
                    //$mEntityConfig = $this->mapFieldToColumn($target, $fieldOrColumn);
                    
                    if ($join->has('foreign')) {
                        $foreignField = $join->get('foreign');
                        $foreignColumn = $this->em->mapFieldToColumn($target, $foreignField); 
                    } else {
                        $foreignColumn = $join->get('foreign_column');
                    }
                } else {
                    $table = $join->get('table');
                    $foreignColumn = $join->get('foreign_column');
                }
                    
                if ($join->has('local')) {
                    $localField = $join->get('local');
                    $localColumn = $this->em->mapFieldToColumn($this->entity, $localField); 
                } else {
                    $localColumn = $join->get('local_column');
                }

                $localColumn = "{$this->aliases[0]}.{$localColumn}";
                $foreignColumn = "{$this->aliases[$field]}.{$foreignColumn}";
                $expression = $ef->eq($foreignColumn, $localColumn);

                if ($join->has('where')) {
                    $whereExpression = $join->get('where');

                    $whereExpression = preg_replace_callback('/\$([^ ]+)/', function($match) use ($field, $target) {
                        $filterField = $match[1];

                        $column = $this->em->mapFieldToColumn($target, $filterField);
                        $column = "{$this->aliases[$field]}.{$column}";
                        return $column;
                    }, $whereExpression);

                    $expression = $ef->andExpr($expression, $whereExpression);
                }

                $alias = $this->aliases[$field];
                $qb->directedJoin(DirectionalJoin::DIRECTION_LEFT, [$alias => $table], $expression);

                /*if ($join->has('order_by')) {
                    $orderBy = $join->get('order_by');
                    $fieldOrColumn = $orderBy[0];
                    $direction = count($orderBy) == 2 ? $orderBy[1] : "ASC";

                    if ($this->fields->has($fieldOrColumn)) {
                        $column = $this->fields->sub($fieldOrColumn)->get($column);
                    }

                    $column = $column ?? $fieldOrColumn;
                    $alias = $this->aliases[$field];
                    $qf = $qf->orderBy("{$alias}.{$column}", $direction);
                }*/
            }

            $whereExpression = null;
            foreach ($this->keys as $key) {
                $value = $this->getFieldValue($skeleton, $key);
                $column = $this->em->mapFieldToColumn($this->entity, $key);

                $expression = $ef->eq("{$this->aliases[0]}.{$column}", ":{$key}");
                $expression->bindValue($key, $value);

                if ($whereExpression) $whereExpression = $ef->andExpr($whereExpression, $expression);
                else $whereExpression = $expression;
            }

            $qf = $qb->where($whereExpression);
            $query = $qb->getQuery();

            $statement = $this->connection->execute($query, $query->getBinds(), $query->getBindTypes());
            $results = $statement->fetchAll();

            if ($results) {
                $fields = $this->em->getLocalFields($this->entity);

                foreach ($this->fields as $field => $config) {
                    if (in_array($field, array_keys($fields))) {
                        $key = strtolower($this->entity) . "_" . $this->em->mapFieldToColumn($this->entity, $field);
                        $value = $results[0][$key];
                    } else {
                        $join = $config->sub('join');

                        if ($config->has('target_entity')) {
                            $target = $config->get('target_entity');
                            $targetRepo = $this->em->getRepository($target);
                            $targetFields = $this->em->getLocalFields($target);
                            
                            if (in_array($join->get('type', 'one'), ['one', 'onetoone', '1:1'])) {
                                array_walk($targetFields, function(&$value, $key) use($target, $field, $results) {
                                    $key = strtolower("{$field}_{$key}");
                                    $value = $results[0][$key];
                                });

                                $value = $targetRepo->get($targetFields);
                            } else {
                                $seenKeys = [];
                                $keys = $targetRepo->getObjectKeys();

                                $realColumns = array_map(function($mField) use ($target, $field) {
                                    return strtolower("{$field}_{$mField}");
                                }, array_keys($targetFields));

                                $realKeys = array_map(function($mField) use ($target, $field) {
                                    return strtolower("{$field}_{$mField}");
                                }, $keys);

                                $slice = $results;
                                array_walk($slice, function(&$row, $index) use ($realColumns) {
                                    $row = array_intersect_key($row, array_flip($realColumns));
                                });

                                $value = array_filter($slice, function($row, $index) use (&$seenKeys, $realKeys) {
                                    $mKeys = array_intersect_key($row, array_flip($realKeys));
                                    $hash = md5(implode(array_values($mKeys)));

                                    if (in_array($hash, $seenKeys)) return false;
                                    else {
                                        $seenKeys[] = $hash;
                                        return array_reduce(array_intersect_key($row, array_flip($realKeys)), function($carry, $value) {
                                            return $carry && $value != null;
                                        }, true);
                                    }
                                }, ARRAY_FILTER_USE_BOTH);

                                array_walk($value, function(&$row, $index) use ($field, $targetRepo, $targetFields) {
                                    $mValue = array_walk($targetFields, function(&$value, $key) use ($row, $field) {
                                        $key = strtolower("{$field}_{$key}");
                                        $value = $row[$key];
                                    });

                                    $row = $targetRepo->get($targetFields);
                                });

                                $value = array_values($value);
                            }
                        } else {
                            $columns = $join->get('columns');
                            $idColumns = $join->get('idColumns', [$columns[0]]);
                            $realColumns = array_map(function($column) use ($field) { return "{$field}_{$column}"; }, $columns);
                            $realIdColumns = array_map(function($column) use ($field) { return "{$field}_{$column}"; }, $idColumns);

                            if (in_array($join->get('type', 'one'), ['one', 'onetoone', '1:1'])) {
                                $row = $results[0];
                                $value = array_intersect_key($row, array_flip($realColumns));
                                
                                $mColumns = array_flip($columns);
                                $value = array_map(function($index) use ($field, $value, $columns) { 
                                    return $value["{$field}_{$columns[$index]}"];
                                }, $mColumns); // remap the columns to the proper names
                            } else {
                                $seenKeys = [];

                                $slice = $results;
                                array_walk($slice, function(&$row, $index) use ($realColumns) {
                                    $row = array_intersect_key($row, array_flip($realColumns));
                                });

                                $value = array_values(array_filter($slice, function($row, $index) use (&$seenKeys, $realIdColumns) {
                                    $keys = array_intersect_key($row, array_flip($realIdColumns));
                                    $hash = md5(implode(array_values($keys)));

                                    if (in_array($hash, $seenKeys)) return false;
                                    else {
                                        $seenKeys[] = $hash;
                                        return array_reduce(array_intersect_key($row, array_flip($realIdColumns)), function($carry, $value) {
                                            return $carry && $value != null;
                                        }, true);
                                    }
                                }, ARRAY_FILTER_USE_BOTH));
                            }
                        }
                    }

                    $skeleton = $this->setFieldValue($skeleton, $field, $value);
                }
            } else {
                $this->orc->invalidate($orcKey); // get rid of the reference
                return null;
            }
            
            // persist it in the orc and in the lt cache
            $this->orc->cache($orcKey, $skeleton);
            $this->entityCache->cache($orcKey, $skeleton);

            return clone $skeleton; // necessary to "sever" the object from the reference cache
        }

        const PERSIST_MODE_INSERT = 0;
        const PERSIST_MODE_UPDATE = 1;
        const AUTO_GENERATED_TYPES = ['serial'];
        public function persist(&$object) {
            $qf = QueryFactory::getFactory();
            $ef = new ExpressionBuilder();
            $mode = Repository::PERSIST_MODE_UPDATE;
            $definition = $this->getDefinition();
            $connection = $this->getConnection();

            foreach ($this->keys as $key) {
                $value = $this->getFieldValue($object, $key);

                if ($value == null) {
                    $type = $definition->get("fields.{$key}.type");
                    if (!in_array($type, self::AUTO_GENERATED_TYPES)) {
                        throw new SebastianException("non auto-generated primary key columns cannot be null ({$type} - {$key})");
                    }

                    $mode = Repository::PERSIST_MODE_INSERT;
                } else {
                    if ($this->get($value) === null) {
                        $mode = Repository::PERSIST_MODE_INSERT;
                    }
                }
            }

            $postPersist = [];
            $localFields = $this->em->getLocalFields($this->entity);
            $fields = $this->getDefinition()->sub('fields');

            if ($mode == Repository::PERSIST_MODE_INSERT) {
                foreach ($fields as $field => $fieldConfig) {
                    $postPersist[$field] = [];
                    $value = $this->getFieldValue($object, $field);
                    $column = $this->em->mapFieldToColumn($this->entity, $field);

                    if (in_array($field, array_keys($localFields))) {
                        if ($value === null) continue;
                        $qf->insert($column, $value);
                    } else { // here we attempt pre-persist fields
                        $join = $fieldConfig->sub('join');

                        if (in_array($join->get('type', 'one'), ['one', 'onetoone', '1:1'])) {
                            if ($fieldConfig->has('target_entity')) {
                                if ($value !== null) {
                                    $targetRepo = $this->em->getRepository($value);
                                    $targetField = $join->get('foreign');

                                    $mValue = $targetRepo->getFieldValue($value, $targetField);

                                    // i hate this
                                    $key = $this->entityCache->generateKey($value);
            
                                    $this->entityCache->invalidate($key);
                                    $this->orc->invalidate($key);

                                    $qf->insert($column, $mValue);                                    
                                }
                            } else {
                                if (is_array($value)) { // when creating the object, this column can be the id
                                    $value = $value[$join->get('local_column')]; // has to be foreign column
                                }
                                
                                $qf->insert($column, $value);
                            }
                        } else {
                            foreach ($value ?? [] as $index => &$mValue) {
                                if ($mValue === null) continue;

                                if ($fieldConfig->has('target_entity')) {
                                    $targetRepo = $this->em->getRepository($mValue);
                                    $targetFields = $targetRepo->getDefinition()->sub('fields');
                                    $targetField = $join->get('foreign');

                                    $targetForeignValue = $targetRepo->getFieldValue($mValue, $targetField);
                                    $inverse = $this->getFieldValue($object, $join->get('local'));

                                    if ($inverse == null) $postPersist[$field][] = $mValue;
                                } else {
                                    // @todo
                                    //$mValue = $mValue[$join->get('local_column')]; // has to be foreign column
                                    //$qf->insert($column, $mValue);
                                }
                            }
                        }
                    }
                }

                foreach ($this->keys as $key) {
                    $column = $this->em->mapFieldToColumn($this->entity, $key);
                    $qf->returning([$column => $key]);
                }

                $qf->into($this->getTable());
                $query = $qf->getQuery();

                $result = $this->getConnection()->execute($query, $query->getBinds());
                $result = $result->fetch(PDO::FETCH_ASSOC);

                $object = $this->build($object, $result);
            } else {
                //$changed = $em->computeObjectChanges($object);

                $qf->update($this->getTable());

                if (($field = $this->config->get('modified_at_field', false)) !== false) {
                    $this->setFieldValue($object, $field, date_create());
                }

                foreach ($fields as $field => $fieldConfig) {
                    $postPersist[$field] = [];
                    $value = $this->getFieldValue($object, $field);
                    $column = $this->em->mapFieldToColumn($this->entity, $field);

                    if (in_array($field, array_keys($localFields))) {
                        if ($value === null || in_array($field, $this->keys)) continue;
                        $qf->set($column, $value);
                    } else { // here we attempt pre-persist fields
                        $join = $fieldConfig->sub('join');

                        if (in_array($join->get('type', 'one'), ['one', 'onetoone', '1:1'])) {
                            if ($fieldConfig->has('target_entity')) {
                                if ($value !== null) {
                                    $targetRepo = $this->em->getRepository($value);
                                    $targetField = $join->get('foreign');

                                    $mValue = $targetRepo->getFieldValue($value, $targetField);

                                    // i hate this
                                    $key = $this->entityCache->generateKey($value);
            
                                    $this->entityCache->invalidate($key);
                                    $this->orc->invalidate($key);

                                    $qf->set($column, $mValue);                                    
                                }
                            } else {
                                if (is_array($value)) { // when creating the object, this column can be the id
                                    $value = $value[$join->get('local_column')]; // has to be foreign column
                                }
                                
                                $qf->set($column, $value);
                            }
                        } else {
                            foreach ($value as $index => &$mValue) {
                                if ($mValue === null) continue;

                                if ($fieldConfig->has('target_entity')) {
                                    $targetRepo = $this->em->getRepository($mValue);
                                    $targetFields = $targetRepo->getDefinition()->sub('fields');
                                    $targetField = $join->get('foreign');

                                    $targetForeignValue = $targetRepo->getFieldValue($mValue, $targetField);
                                    $inverse = $this->getFieldValue($object, $join->get('local'));

                                    if ($inverse == null) $postPersist[$field][] = $mValue;
                                    else {
                                        //$mValue = $targetRepo->persist($mValue);
                                    }
                                } else {
                                    // @todo
                                    //$mValue = $mValue[$join->get('local_column')]; // has to be foreign column
                                    //$qf->insert($column, $mValue);
                                }
                            }
                        }
                    }
                }

                $whereExpression = null;
                foreach ($this->keys as $key) {
                    $value = $this->getFieldValue($object, $key);

                    $column = $this->em->mapFieldToColumn($this->entity, $key);
                    $qf->bind($key, $value);
                    $expression = $ef->eq("{$column}", ":{$key}");

                    $whereExpression = $whereExpression == null ? 
                        $expression : $ef->andExpr($whereExpression, $expression);
                }

                $qf = $qf->where($whereExpression);
                $query = $qf->getQuery();

                $result = $connection->execute($query, $query->getBinds());
            }

            foreach ($postPersist as $field => $fieldValues) {
                $fieldConfig = $fields->sub($field);

                foreach ($fieldValues as &$fieldValue) {
                    if ($fieldConfig->has('target_entity')) {
                        $targetRepo = $this->em->getRepository($fieldValue);
                        $fieldValue = $targetRepo->persist($fieldValue);
                    }
                }
            }

            $key = $this->entityCache->generateKey($object);
            
            $this->entityCache->invalidate($key);
            $this->orc->invalidate($key);
            
            return $object;
        }

        public function refresh($object) {
            $associativeKeys = array_flip($this->keys);
            $params = array_walk($associativeKeys, function(&$value, $key) use ($object) {
                $value = $this->getFieldValue($object, $key);
            });

            return $this->get($params);
        }

        public function generateColumnMap() {
            $columnMap = new Collection();
            $definition = $this->getDefinition();

            foreach ($definition->sub('fields') as $key => $field) {
                if (!$field->has('column')) $columnMap->set($key, $key);
                else $columnMap->set($field->get('column'), $key);
            }

            return $columnMap;
        }

        public function generateFieldMap() {
            $fieldMap = new Collection();
            $definition = $this->getDefinition();

            foreach ($definition->sub('fields') as $key => $field) {
                if (!$field->has('column')) $fieldMap->set($key, $key);
                else $fieldMap->set($key, $field->get('column'));
            }

            return $fieldMap;
        }

        public function setFieldValue($object, $fieldName, $value) {
            $useReflection = $this->config->get('use_reflection', false);
            $field = $this->fields->sub($fieldName);
            $type = $field->get('type', null);

            if ($type != null) {
                if ($field->has('transformer')) {
                    $mTransformer = $field->get('transformer'); 
                    $transformer = $this->em->getTransformer($mTransformer);

                    if ($transformer == null) {
                        throw new SebastianException("Unable to find transformer {$mTransformer}.");
                    }
                } else $transformer = $this->em->getTransformer($type);
                
                if ($transformer != null) {
                    $value = $transformer->transform($value);
                }
            }

            if ($useReflection) {
                $field = $this->reflection->getProperty($fieldName);
                $inaccessible = $field->isPrivate() || $field->isProtected();
                
                if ($inaccessible) {
                    $field->setAccessible(true);
                    $field->setValue($object, $value);
                    $field->setAccessible(false); // reset
                } else {
                    $field->setValue($object, $value);
                }
            } else {
                $method = $this->getSetterMethod($fieldName, false);
                if ($method) $object->{$method}($value);
            }

            return $object;
        }

        public function getFieldValue($object, $fieldName) {
            $useReflection = $this->config->get('use_reflection', false);
            $field = $this->fields->sub($fieldName);
            $type = $field->get('type', null);

            if ($useReflection) {
                $field = $this->reflection->getProperty($fieldName);
                $inaccessible = $field->isPrivate() || $field->isProtected();
                    
                if ($inaccessible) {
                    $field->setAccessible(true);
                    $value = $field->getValue($object);
                    $field->setAccessible(false); // reset
                } else $value = $field->getValue($object);
            } else {
                $method = $this->getGetterMethod($field);
                $value = $object->{$method}();
            }

            if ($type != null) {
                $transformer = $this->em->getTransformer($type);

                if ($transformer != null) {
                    $value = $transformer->reverseTransform($value);
                }
            }

            return $value;
        }

        public function getGetterMethod($key, $die = true) {
            if ($this->genericEntity) return "get{$key}";

            foreach (['get','is','has'] as $prefix) {
                $methodName = $key;
                $methodName[0] = strtoupper($methodName[0]);
                $methodName = $prefix . $methodName;

                if (method_exists($this->entityClass, $methodName)) {
                    return $methodName;
                }
            }
            
            if ($die) {
                throw new \Exception("No 'get' method found for {$key} in {$this->entity}");    
            } else return null;
        }

        public function getSetterMethod($key, $die = true) {
            if ($this->genericEntity) return "set{$key}";

            foreach (['set','add','put'] as $prefix) {
                $methodName = $key;
                $methodName[0] = strtoupper($methodName[0]);
                $methodName = $prefix . $methodName;

                if (method_exists($this->entityClass, $methodName)) {
                    return $methodName;
                }
            }
            
            if ($die) {
                throw new \Exception("No 'set' method found for {$key} in {$this->entity}");
            } else return null;
        }

        public function getColumnMap() {
            return $this->columnMap;
        }

        public function getConnection() {
            return $this->em->getConnection();
        }

        public function getDefinition() {
            return $this->definition;
        }

        public function getFieldMap() {
            return $this->fieldMap;
        }

        public function getObjectKeys() {
            return $this->keys;
        }

        public function getTable() {
            return $this->table;
        }

        public function setTransformer(TransformerInterface $transformer) {
            $this->transformer = $transformer;
        }

        public function getTransformer() {
            return $this->transformer;
        }
    }