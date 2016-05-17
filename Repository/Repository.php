<?php
    namespace SebastianExtra\Repository;

    use \PDO;

    use SebastianExtra\EntityManager\EntityManager;

    use Sebastian\Core\Cache\CacheManager;
    use Sebastian\Core\Database\Query\Expression\ExpressionFactory;
    use Sebastian\Core\Database\Query\Part\Join;
    use Sebastian\Core\Database\Query\QueryFactory;
    use Sebastian\Core\Exception\SebastianException;
    use Sebastian\Core\Repository\Transformer\TransformerInterface;

    use Sebastian\Utility\Collection\Collection;
    use Sebastian\Utility\Configuration\Configuration;
    use Sebastian\Utility\Utility\Utils;

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

        protected $config;

        protected $entity;
        protected $class;
        protected $definition;
        
        protected $connection;
        protected $em;
        protected $cm;
        protected $orc;
        
        public function __construct(EntityManager $entityManager, CacheManager $cacheManager = null, Logger $logger = null, Configuration $config = null, $entity = null) {
            $this->entityManager = $entityManager;
            $this->cacheManager = $cacheManager;
            $this->logger = $logger;
            $this->connection = $entityManager->getConnection();

            if ($config == null) $config = new Configuration();
            $this->config = $config->extend([ 'use_reflection' => true ]);

            $this->entity = $entity;
            $this->class = $entityManager->getNamespacePath($this->entity);
            $this->orc = $entityManager->getObjectReferenceCache();

            $this->initCalled = false;
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
            $this->initCalled = true;

            if ($this->entity != null) {
                $this->definition = $this->entityManager->getDefinition($this->entity);
                $this->nsPath = $this->entityManager->getNamespacePath($this->entity);

                $this->table = $this->definition->get('table');
                $this->keys = $this->definition->get('keys');
                $this->fields = $this->definition->sub('fields');

                $this->joins = $this->entityManager->computeJoinSets($this->entity);
                $this->aliases = $this->entityManager->generateTableAliases($this->entity, $this->joins);
                $this->columns = $this->entityManager->computeColumnSets($this->entity, $this->joins, $this->aliases);
            }

            $this->reflection = new \ReflectionClass($this->class);
            $this->columnMap = $this->generateColumnMap();
            $this->fieldMap = $this->generateFieldMap();
        }

        /**
         * [build description]
         * @param  [type] $object [description]
         * @param  array  $params [description]
         * @return [type]         [description]
         */
        public function build($object = null, $fields = []) {
            if (!$object) {
                $classPath = $this->entityManager->getNamespacePath($this->entity);
                $object = new $classPath();
            }

            if (!$fields) $fields = [];

            foreach ($fields as $field => $value) {
                $object = $this->setFieldValue($object, $field, $value);
            }

            return $object;
        }

        public function delete($object) {
            $qf = QueryFactory::getFactory();
            $ef = ExpressionFactory::getFactory();

            $qf = $qf->delete()->from($this->getTable());

            $mEf = ExpressionFactory::getFactory();
            foreach ($this->keys as $key) {
                $value = $this->getFieldValue($object, $key);

                $ef->reset()->expr($key)->equals($value);
                $mEf->andExpr($ef->getExpression());
            }

            $qf = $qf->where($mEf->getExpression());
            $query = $qf->getQuery();

            $result = $this->connection->execute($query, []);

            $key = $this->cacheManager->generateKey($object);
            $this->cacheManager->invalidate($key);
        }

        public function find($where = [], $options = []) {
            $where = $where ?: [];

            $em = $this->entityManager; // convenience
            $qf = QueryFactory::getFactory();
            $ef = ExpressionFactory::getFactory();

            $keys = array_map(function($field) use ($em) {
                return $em->mapFieldToColumn($this->entity, $field);
            }, $this->keys);

            //$qf = $qf->select($this->columns)->from([
            $qf = $qf->select($keys)->from([
                $this->aliases[0] => $this->getTable()
            ]);

            /*foreach ($this->joins as $field => $join) {
                $fieldConfig = $this->fields->sub($field);

                if ($fieldConfig->has('targetEntity')) {
                    $target = $fieldConfig->get('targetEntity');
                    $mEntityConfig = $this->entityManager->getDefinition($target);
                    $table = $mEntityConfig->get('table');
                    $foreignColumn = $join->get(
                        'foreignColumn', 
                        $this->entityManager->mapFieldToColumn($target, $join->get('foreign'))
                    );
                } else {
                    $table = $join->get('table');
                    $foreignColumn = $join->get('foreignColumn');
                }

                $localColumn = $this->entityManager->mapFieldToColumn($this->entity, $join->get('local'));
                $withEntityKey = "{$this->aliases[0]}.{$localColumn}";

                $alias = $this->aliases[$field];
                $expression = $ef->reset()->expr($withEntityKey)
                        ->equals("{$alias}.{$foreignColumn}")
                        ->getExpression();

                $qf = $qf->join(Join::TYPE_LEFT, [$alias => $table], $expression);
            }*/

            $expression = null;
            foreach ($where as $with => $param) {
                $column = $em->mapFieldToColumn($this->entity, $with);

                if ($param instanceof Expression) {
                    $ef = $ef->reset()->expr($param);
                    
                    if ($expression) {
                        $mExpression = $ef->getExpression();
                        $ef = $ef->reset()->expr($mExpression)->andExpr($expression);
                    }

                    $expression = $ef->getExpression();
                } else {
                    $lhs = "{$this->aliases[0]}.{$column}";
                    $ef = $ef->reset()->expr($lhs);

                    if (is_array($param)) {
                        print ("IN");
                        //$ef->in($param);
                    }

                    // todo allow for tables like [['table' => 'column'] => param]
                    if (preg_match("/(!|not|>|<) ?(.+)/i", $param, $matches) >= 1) {
                        $operator = $matches[1];
                        $param = $matches[2];

                        if ($operator == '!' || $operator == 'not') $ef = $ef->notEquals($param);
                        else if ($operator == '<') $ef = $ef->lessThan($param);
                        else if ($operator == '>') $ef = $ef->greaterThan($param);
                    } else {
                        //$param = "{$this->aliases[$this->entity]}.{$param}";
                        // todo if (is_array $param)

                        if (is_bool($param)) {
                            $ef = $ef->is($param ? "TRUE" : "FALSE");
                        } else {
                            $qf->bind($column, $param);
                            $ef = $ef->equals(":{$column}");
                        }
                    }

                    if ($expression) {
                        //print ($expression);
                        $mExpression = $ef->getExpression();
                        $ef = $ef->reset()->expr($expression)->andExpr($mExpression);
                    }

                    $expression = $ef->getExpression();
                }
            }

            if ($expression) $qf = $qf->where($expression);

            if ($options && count($options) != 0) {
                if (isset($options['limit'])) $qf = $qf->limit($options['limit']);
                if (isset($options['offset'])) $qf = $qf->offset($options['offset']);
                if (isset($options['orderBy'])) {
                    foreach ($options['orderBy'] as $field => $direction) {
                        $column = $this->entityManager->mapFieldToColumn($this->entity, $field);
                        $column = "{$this->aliases[0]}.{$column}";
                        $qf = $qf->orderBy($column, $direction);
                    }
                }
            }

            $query = $qf->getQuery();
            $result = $this->connection->execute($query, $query->getBinds());
            $results = $result->fetchAll() ?: [];

            $localFields = $em->getLocalFields($this->entity);
            $skeletons = []; // list of final objects

            //var_dump($this->getFieldMap()); die();
            foreach ($results as $index => $row) {
                $params = [];
                foreach ($row as $key => $value) {
                    $params[$this->getColumnMap()[$key]] = $value;
                }

                $skeletons[] = $this->get($params);
            }


            
            //$seenLocalkeys = [];

            /*foreach ($results as $mResult) {
                foreach ($this->fields as $field => $config) {
                    if (in_array($field, array_keys($localFields))) {
                        $key = strtolower($this->entity) . "_" . $this->entityManager->mapFieldToColumn($this->entity, $field);
                        $value = $mResult[$key];
                    } else {
                        $join = $config->sub('join');

                        if ($config->has('targetEntity')) {
                            $target = $config->get('targetEntity');
                            $targetRepo = $this->entityManager->getRepository($target);
                            $targetFields = $this->entityManager->getLocalFields($target);
                            
                            if (in_array($join->get('type', 'one'), ['one', 'onetoone', '1:1'])) {
                                array_walk($targetFields, function(&$value, $key) use($target, $field, $results) {
                                    $key = strtolower("{$field}_{$key}");
                                    $value = $mResult[$key];
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

                                $slice = $mResult;
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
                                $value = array_intersect_key($mResult, array_flip($realColumns));
                            } else {
                                $seenKeys = [];

                                $slice = $mResult;
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
                
                $mSkeletons[] = $skeleton;
            }*/

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
                if (count($this->keys) != 1) throw new SebastianException(
                    "Cannot use simplified method signature when entity has more than one primary key"
                );

                $params = [ $this->keys[0] => $params ];
            }

            $qf = QueryFactory::getFactory();
            $ef = ExpressionFactory::getFactory();

            if (empty(array_intersect($this->keys, array_keys($params)))) {
                $keys = implode(', ', $this->keys);
                throw new SebastianException("One of [{$keys}] must be provided for entity {$this->entity}", 500);
            }

            // check temp cache
            $skeleton = $this->build(null, $params);
            foreach ($this->keys as $key) {
                if ($this->getFieldValue($skeleton, $key) == null) {
                    return null;
                }
            }

            $orcKey = $this->orc->generateKey($skeleton);

            if ($this->orc->isCached($orcKey)) {
                //$this->logger->info("hit _orc with {$orcKey}", "repo_log");
                return $this->orc->load($orcKey);
            } else {
                $this->orc->cache($orcKey, $skeleton);
            }

            // then check long term cache
            $cmKey = $this->cacheManager->generateKey($skeleton);
            if ($this->cacheManager->isCached($cmKey)) {
                return $this->cacheManager->load($cmKey);
            }

            $qf = $qf->select($this->columns)
                     ->from([$this->aliases[0] => $this->getTable()]);

            foreach ($this->joins as $field => $join) {
                $fieldConfig = $this->fields->sub($field);

                if ($fieldConfig->has('targetEntity')) {
                    $target = $fieldConfig->get('targetEntity');
                    $mEntityConfig = $this->entityManager->getDefinition($target);
                    $table = $mEntityConfig->get('table');
                    $foreignColumn = $join->get(
                        'foreignColumn', 
                        $this->entityManager->mapFieldToColumn($target, $join->get('foreign'))
                    );
                } else {
                    $table = $join->get('table');
                    $foreignColumn = $join->get('foreignColumn');
                }

                $localColumn = $this->entityManager->mapFieldToColumn($this->entity, $join->get('local'));
                $withEntityKey = "{$this->aliases[0]}.{$localColumn}";

                $alias = $this->aliases[$field];
                $expression = $ef->reset()->expr($withEntityKey)
                        ->equals("{$alias}.{$foreignColumn}")
                        ->getExpression();

                $qf = $qf->join(Join::TYPE_LEFT, [$alias => $table], $expression);
            }

            $ef->reset();
            $mExFactory = ExpressionFactory::getFactory();
            foreach ($this->keys as $key) {
                $value = $this->getFieldValue($skeleton, $key);

                $column = $this->entityManager->mapFieldToColumn($this->entity, $key);
                $mExFactory->reset()->expr("{$this->aliases[0]}.{$column}")
                    ->equals(":$key"); // generates the individual expression

                $qf->bind($key, $value);
                $ef->andExpr($mExFactory->getExpression()); // handles generating the final expression
            }

            $qf = $qf->where($ef->getExpression());
            $query = $qf->getQuery();

            $statement = $this->connection->execute($query, $query->getBinds());
            $results = $statement->fetchAll();

            if ($results) {
                $fields = $this->entityManager->getLocalFields($this->entity);

                foreach ($this->fields as $field => $config) {
                    if (in_array($field, array_keys($fields))) {
                        $key = strtolower($this->entity) . "_" . $this->entityManager->mapFieldToColumn($this->entity, $field);
                        $value = $results[0][$key];
                    } else {
                        $join = $config->sub('join');

                        if ($config->has('targetEntity')) {
                            $target = $config->get('targetEntity');
                            $targetRepo = $this->entityManager->getRepository($target);
                            $targetFields = $this->entityManager->getLocalFields($target);
                            
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
            $this->cacheManager->cache(null, $skeleton);

            return clone $skeleton; // necessary to "sever" the object from the reference cache
        }

        const PERSIST_MODE_INSERT = 0;
        const PERSIST_MODE_UPDATE = 1;
        const AUTO_GENERATED_TYPES = ['serial'];
        public function persist(&$object) {
            $qf = QueryFactory::getFactory();
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
                    if ($this->get($value) == null) {
                        $mode = Repository::PERSIST_MODE_INSERT;
                    }
                }
            }

            if ($mode == Repository::PERSIST_MODE_INSERT) {
                $postPersist = [];
                $localFields = $this->entityManager->getLocalFields($this->entity);
                $fields = $this->getDefinition()->sub('fields');

                foreach ($fields as $field => $fieldConfig) {
                    $postPersist[$field] = [];
                    $value = $this->getFieldValue($object, $field);
                    $column = $this->entityManager->mapFieldToColumn($this->entity, $field);

                    if (in_array($field, array_keys($localFields))) {
                        if ($value == null) continue;
                        $qf->insert($column, $value);
                    } else { // here we attempt pre-persist fields
                        $join = $fieldConfig->sub('join');

                        if (in_array($join->get('type', 'one'), ['one', 'onetoone', '1:1'])) {
                            if ($fieldConfig->has('targetEntity')) {
                                if ($value !== null) {
                                    $targetRepo = $this->entityManager->getRepository($value);
                                    $targetField = $join->get('foreign');

                                    $value = $targetRepo->persist($value);
                                    $mValue = $targetRepo->getFieldValue($value, $targetField);

                                    $qf->insert($column, $mValue);                                    
                                }
                            } else {
                                if (is_array($value)) { // when creating the object, this column can be the id
                                    $value = $value[$join->get('foreignColumn')]; // has to be foreign column
                                }
                                
                                $qf->insert($column, $value);
                            }
                        } else {
                            foreach ($value as $index => &$mValue) {
                                if ($mValue === null) continue;

                                if ($fieldConfig->has('targetEntity')) {
                                    $targetRepo = $this->entityManager->getRepository($mValue);
                                    $targetFields = $targetRepo->getDefinition()->sub('fields');
                                    $targetField = $join->get('foreign');

                                    $targetForeignValue = $targetRepo->getFieldValue($mValue, $targetField);
                                    $inverse = $this->getFieldValue($object, $join->get('local'));

                                    if ($inverse == null) $postPersist[$field][] = $mValue;
                                    else {
                                        $mValue = $targetRepo->persist($mValue);
                                    }
                                } else {
                                    // @todo
                                    //$mValue = $mValue[$join->get('foreignColumn')]; // has to be foreign column
                                    //$qf->insert($column, $mValue);
                                }
                            }
                        }
                    }
                }

                foreach ($this->keys as $key) {
                    $column = $this->entityManager->mapFieldToColumn($this->entity, $key);
                    $qf->returning([$column => $key]);
                }

                $qf->into($this->getTable());
                $query = $qf->getQuery();

                $result = $this->getConnection()->execute($query, $query->getBinds());
                $result = $result->fetch(PDO::FETCH_ASSOC);

                $object = $this->build($object, $result);

                foreach ($postPersist as $field => $fieldValues) {
                    $fieldConfig = $fields->sub($field);

                    foreach ($fieldValues as &$fieldValue) {
                        if ($fieldConfig->has('targetEntity')) {
                            $targetRepo = $this->entityManager->getRepository($fieldValue);
                            $fieldValue = $targetRepo->persist($fieldValue);
                        }
                    }
                }

                //$object = $this->refresh($object);
            } else {
                //$changed = $em->computeObjectChanges($object);
                $postPersist = [];
                $localFields = $this->entityManager->getLocalFields($this->entity);
                $fields = $this->getDefinition()->sub('fields');

                $qf->update($this->getTable());

                foreach ($fields as $field => $fieldConfig) {
                    $postPersist[$field] = [];
                    $value = $this->getFieldValue($object, $field);
                    $column = $this->entityManager->mapFieldToColumn($this->entity, $field);

                    if (in_array($field, array_keys($localFields))) {
                        if ($value == null || in_array($field, $this->keys)) continue;
                        $qf->set($column, $value);
                    } else { // here we attempt pre-persist fields
                        $join = $fieldConfig->sub('join');

                        if (in_array($join->get('type', 'one'), ['one', 'onetoone', '1:1'])) {
                            if ($fieldConfig->has('targetEntity')) {
                                if ($value !== null) {
                                    $targetRepo = $this->entityManager->getRepository($value);
                                    $targetField = $join->get('foreign');

                                    $value = $targetRepo->persist($value);
                                    $mValue = $targetRepo->getFieldValue($value, $targetField);

                                    $qf->set($column, $mValue);                                    
                                }
                            } else {
                                if (is_array($value)) { // when creating the object, this column can be the id
                                    $value = $value[$join->get('foreignColumn')]; // has to be foreign column
                                }
                                
                                $qf->set($column, $value);
                            }
                        } else {
                            foreach ($value as $index => &$mValue) {
                                if ($mValue === null) continue;

                                if ($fieldConfig->has('targetEntity')) {
                                    $targetRepo = $this->entityManager->getRepository($mValue);
                                    $targetFields = $targetRepo->getDefinition()->sub('fields');
                                    $targetField = $join->get('foreign');

                                    $targetForeignValue = $targetRepo->getFieldValue($mValue, $targetField);
                                    $inverse = $this->getFieldValue($object, $join->get('local'));

                                    if ($inverse == null) $postPersist[$field][] = $mValue;
                                    else {
                                        $mValue = $targetRepo->persist($mValue);
                                    }
                                } else {
                                    // @todo
                                    //$mValue = $mValue[$join->get('foreignColumn')]; // has to be foreign column
                                    //$qf->insert($column, $mValue);
                                }
                            }
                        }
                    }
                }

                $ef = ExpressionFactory::getFactory();
                $mEf = ExpressionFactory::getFactory();
                foreach ($this->keys as $key) {
                    $value = $this->getFieldValue($object, $key);

                    $column = $this->entityManager->mapFieldToColumn($this->entity, $key);
                    $mEf->reset()->expr("{$column}")
                        ->equals(":{$key}"); // generates the individual expression

                    $qf->bind($key, $value);
                    $ef->andExpr($mEf->getExpression()); // handles generating the final expression
                }

                $qf = $qf->where($ef->getExpression());

                $query = $qf->getQuery();
                //print($query); die();
                $result = $connection->execute($query, $query->getBinds());

                foreach ($postPersist as $field => $fieldValues) {
                    $fieldConfig = $fields->sub($field);

                    foreach ($fieldValues as &$fieldValue) {
                        if ($fieldConfig->has('targetEntity')) {
                            $targetRepo = $this->entityManager->getRepository($fieldValue);
                            $fieldValue = $targetRepo->persist($fieldValue);
                        }
                    }
                }
            }

            $key = $this->cacheManager->generateKey($object);
            //var_dump($key); throw \PDOException("asdasd");
            $this->cacheManager->invalidate($key);
            
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
            $columnMap = new Collection();
            $definition = $this->getDefinition();

            foreach ($definition->sub('fields') as $key => $field) {
                if (!$field->has('column')) $columnMap->set($key, $key);
                else $columnMap->set($key, $field->get('column'));
            }

            return $columnMap;
        }

        public function setFieldValue($object, $fieldName, $value) {
            $useReflection = $this->config->get('use_reflection', false);
            $field = $this->fields->sub($fieldName);
            $type = $field->get('type', null);

            if ($type != null) {
                if ($field->has('transformer')) {
                    $mTransformer = $field->get('transformer'); 
                    $transformer = $this->entityManager->getTransformer($mTransformer);

                    if ($transformer == null) {
                        throw new SebastianException("Unable to find transformer {$mTransformer}.");
                    }
                } else $transformer = $this->entityManager->getTransformer($type);
                
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
                $transformer = $this->entityManager->getTransformer($type);

                if ($transformer != null) {
                    $value = $transformer->reverseTransform($value);
                }
            }

            return $value;
        }

        public function getGetterMethod($key, $die = true) {
            foreach (['get','is','has'] as $prefix) {
                $methodName = $key;
                $methodName[0] = strtoupper($methodName[0]);
                $methodName = $prefix . $methodName;

                if (method_exists($this->entityManager->getNamespacePath($this->entity), $methodName)) {
                    return $methodName;
                }
            }
            
            if ($die) {
                throw new \Exception("No 'get' method found for {$key} in {$this->entity}");    
            } else return null;
        }

        public function getSetterMethod($key, $die = true) {
            foreach (['set','add','put'] as $prefix) {
                $methodName = $key;
                $methodName[0] = strtoupper($methodName[0]);
                $methodName = $prefix . $methodName;

                if (method_exists($this->entityManager->getNamespacePath($this->entity), $methodName)) {
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
            return $this->entityManager->getConnection();
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

        public function setTransformer(ColumnTransformerInterface $transformer) {
            $this->transformer = $transformer;
        }

        public function getTransformer() {
            return $this->transformer;
        }
    }