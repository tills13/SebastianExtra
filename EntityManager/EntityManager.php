<?php
    namespace SebastianExtra\EntityManager;

    use \ReflectionClass;
    use \ReflectionException;

    use Sebastian\Core\Cache\CacheManager;
    use Sebastian\Core\Context\ContextInterface;
    use Sebastian\Core\Database\Query\Expression\ExpressionBuilder;
    use Sebastian\Core\Model\EntityInterface;
    use Sebastian\Core\Exception\SebastianException;

    use SebastianExtra\Repository\Repository;
    use SebastianExtra\Repository\Transformer\DatetimeTransformer;
    use SebastianExtra\Repository\Transformer\TransformerInterface;

    use Sebastian\Utility\Collection\Collection;
    use Sebastian\Utility\Configuration\Configuration;
    use Sebastian\Utility\Utility\Utils;
    
    /**
     * EntityManager
     * 
     * @author Tyler <tyler@sbstn.ca>
     * @since  Oct. 2015
     */
    class EntityManager {
        protected $context;
        protected $definitions; // orm definitions
        protected $repositories;
        protected $transformers;
        protected $logger;

        protected static $_objectReferenceCache;

        public function __construct(ContextInterface $context, Configuration $config = null) {
            $this->context = $context;
            $this->config = $config;
            $this->entities = $config->sub('entities', []);

            $this->definitions = Configuration::fromFilename('orm.yaml');
            $this->logger = $context->getLogger();

            $this->repositoryStore = new Collection();
            $this->transformers = new Collection();

            // volatile storage to keep objects until the FPM process dies i.e. per Request
            if (EntityManager::$_objectReferenceCache == null) {
                EntityManager::$_objectReferenceCache = new CacheManager(
                    $this->context,
                    new Configuration([
                        'driver' => CacheManager::ARRAY_DRIVER,
                        'logging' => true
                    ])
                );
            }

            $this->addTransformer(new DatetimeTransformer());
        }

        public function delete($object) {
            $connection = $this->getConnection();
            $connection->beginTransaction();

            try {
                $repo = $this->getRepository($object);
                if ($repo) $object = $repo->delete($object);

                $connection->commit();
            } catch(PDOException $e) {
                $connection->rollback();
                throw $e; // rethrow
            }

            return $object;
        }
        
        public function persist($object) {
            $connection = $this->getConnection();
            $connection->beginTransaction();

            try {
                $repo = $this->getRepository($object);

                if ($repo) {
                    $object = $repo->persist($object);
                    $connection->commit();
                    return $object;
                } else {
                    //var_dump($object);
                    //throw new SebastianException("Repo not found for " . get_class($object));
                }                
            } catch(PDOException $e) {
                $connection->rollback(); die();
                throw $e; // rethrow
            }
        }

        /**
         * refreshed an object from the Database
         * @param  Entity $object the entity to refresh
         * @return Entity $object
         */
        public function refresh($object) {
            $repo = $this->getRepository($object);
            if ($repo) {
                return $repo->refresh($object);
            }
        }

        public function computeColumnSets($entity, $joins, $aliases) {
            $localFields = $this->getLocalFields($entity);

            $em = $this;
            $columns = array_map(function($column) use ($em, $entity, $aliases) {
                $column = $em->mapFieldToColumn($entity, $column);
                $entity = strtolower($entity);
                return [ "{$entity}_{$column}" => "{$aliases[0]}.{$column}" ];
            }, array_keys($localFields));

            $definition = $this->getDefinition($entity);
            $entityFields = $definition->sub('fields', []);
            
            foreach ($joins as $field => $join) {
                $fieldConfig = $entityFields->sub($field);

                if ($fieldConfig->has('targetEntity')) {
                    $target = $fieldConfig->get('targetEntity');
                    $mEntityDefinition = $this->getDefinition($target);
                    $mFields = $this->getLocalFields($target);
                    
                    $mColumns = array_map(function($value, $key) use ($em, $target, $field, $aliases) {
                        $column = $em->mapFieldToColumn($target, $key);
                        return [ "{$field}_{$key}" => "{$aliases[$field]}.{$column}" ];
                    }, $mFields, array_keys($mFields));
                } else {
                    if (!$join->has('columns')) {
                        throw new SebastianException("Arbitrary joins require a column definition");
                    } else {
                        $mColumns = $join->get('columns');
                        $mColumns = array_map(function($column) use ($field, $aliases) {
                            return [ "{$field}_{$column}" => "{$aliases[$field]}.{$column}" ];
                        }, $mColumns);
                    }

                    //$mColumns = ["{$aliases[$field]}.*"]; // select everything from that table 
                }

                $columns = array_merge($columns, $mColumns);
            }

            return $columns;
        }

        /**
         * [computeJoinSets description]
         * @param  [type] $class [description]
         * @return [type]        [description]
         *
         * @todo need to attempt to resolve unknown class
         */
        public function computeJoinSets($entity) {
            if (!$this->definitions->has($entity)) {
                throw new SebastianException("Unable to find entity {$entity}");
            }

            $joins = [];
            $definition = $this->getDefinition($entity);

            foreach ($definition->sub('fields') as $field => $fieldData) {
                if (!$fieldData->has('join')) continue;
                else {
                    $joins[$field] = $fieldData->sub('join');
                }
            }

            return $joins;
        }

         /**
         * computes all the changed fields
         * @param  Entity $object
         * @return array []
         */
        
        public function computeObjectChanges($object) {
            $objectKey = $this->getObjectCache()->generateKey($object);
            $cached = $this->getObjectCache()->load($objectKey);

            $class = $this->getBestGuessClass(get_class($object));
            $definition = $this->getDefinition($class);
            $repo = $this->getRepository($class);
            
            $changed = [];
            foreach ($definition->sub('fields') as $name => $field) {
                $objectVal = $repo->getFieldValue($object, $name);
                $cachedVal = $repo->getFieldValue($cached, $name);

                if ($field->has('targetEntity')) {
                    if (in_array($field->get('relation'), ['1:1', 'one', 'onetoone'])) {
                        $mRepo = $this->getRepository(get_class($objectVal));
                        $keysA = $mRepo->getPrimaryKeys($objectVal);
                        $keysB = $mRepo->getPrimaryKeys($cachedVal);

                        if ($keysA !== $keysB) $changed[] = $name;
                        else {
                            // todo figure this shit out
                            // $mChanges = $this->computeObjectChanges($objectVal);
                            // if (count($mChanges) != 0) $changed[] = $name;
                        }
                    } else if (in_array($field->get('relation'), ['1:x', 'many'])) {
                    } else if ($field->has('join')) {
                    } else { /* ???? */ }
                } else {
                    if ($objectVal != $cachedVal) $changed[] = $name;
                }  
            }

            return $changed;
        }

        public function generateTableAliases($entity, $joins = []) {
            $definition = $this->getDefinition($entity);
            $entityFields = $definition->sub('fields', []);

            $aliases = [];
            $aliases[0] = substr($definition->get('table'), 0, 1);

            foreach ($joins as $field => $join) {
                $fieldConfig = $entityFields->sub($field);
                $join = $fieldConfig->sub('join');

                if ($fieldConfig->has('targetEntity')) {
                    $mEntityDefinition = $this->getDefinition($fieldConfig->get('targetEntity'));
                    $table = $mEntityDefinition->get('table');
                } else {
                    // todo handle join tables
                    $table = $join->get('table');
                }

                $alias = null;
                $index = -1;
                while(!$alias || in_array($alias, $aliases)) {
                    $alias = substr($table, 0, 1) . ($index++ == -1 ? "" : $index);
                }

                $aliases[$field] = $alias;
            }

            return $aliases;
        }

        public function getConnection() {
            return $this->context->getConnection();
        }

        public function getDefinition($entity) {
            if (!$this->definitions->has($entity)) {
                throw new SebastianException("Entity {$entity} not found.");
            }

            return $this->definitions->sub($entity);
        }

        /**
         * @todo split entities and repos
         * @return [type]
         */
        public function getNamespacePath($class) {
            if ($this->entities->has($class)) {
                $entityInfo = $this->entities[$class];
                $entity = $entityInfo['entity'];

                $component = substr($entity, 0, strpos($entity, ':'));
                $subNamespace = substr($entity, strpos($entity, ':') + 1);
                $component = $this->context->getComponent($component);
                return "{$component->getNamespace()}\\{$subNamespace}";
            } else return null;
        }

        public function getLocalFields($entity) {
            if (!$this->definitions->has($entity)) {
                throw new SebastianException("Unknown entity '{$entity}'");//SebastianException
            }

            return array_filter($this->definitions[$entity]['fields'], function($field) {
                return !array_key_exists('join', $field);
            });
        }

        public function getRepository($class) {
            try {
                if (is_object($class) || !$this->entities->has($class)) {
                    $reflection = new ReflectionClass($class);
                    $class = $reflection->getShortName();
                }

                if ($this->entities->has($class)) {
                    $info = $this->entities->sub($class);

                    if ($info->has('config')) $config = $info->sub('config');
                    else if ($this->config->has('repository')) $config = $this->config->sub('repository');
                    else $config = new Configuration();

                    if ($info->has('repository')) {
                        $repo = $info->get('repository');
                        $repository = null;

                        if (strstr($repo, ':')) {
                            $component = substr($repo, 0, strpos($repo, ':'));
                            $component = $this->context->getComponent($component);
                            
                            $repo = substr($repo, strpos($repo, ':') + 1);
                            if (!Utils::endsWith($repo, 'Repository')) $repo = $repo . 'Repository';

                            if ($component->hasClass($repo)) {
                                $repository = $component->getClass($repo);
                            }
                        } else {
                            $components = $this->context->getComponents(true);
                            if (!Utils::endsWith($repo, 'Repository')) $repo = $repo . 'Repository';

                            foreach ($components as $component) {
                                if ($component->hasClass($repo)) {
                                    $repository = $component->getClass($repo);
                                }
                            }
                        }

                        if ($repository) {
                            $repo = new $repository($this, $this->context->getCacheManager(), null, $config, $class);
                            return $repo;
                        }
                    } else {
                        return new Repository($this, $this->context->getCacheManager(), null, $config, $class);
                    }
                } else {
                    throw new SebastianException("No repository found for '{$class}'");
                }
            } catch (ReflectionException $e) {
                die($e->getMessage());
            }

            throw new SebastianException("No repository found for '{$class}'");
        }

        public function addTransformer(TransformerInterface $transformer) {
            return $this->setTransformer($transformer->getName(), $transformer);
        }

        public function setTransformer($name, $transformer) {
            return $this->transformers->set($name, $transformer);
        }

        public function getTransformer($name) {
            return $this->transformers->get($name, null);
        }

        public function getTransformers() {
            return $this->transformers;
        }

        public function getColumnMap($entity) {
            $definition = $this->getDefinition($entity);
            $fields = $definition->get('fields');

            return array_map(function($field) {
                if (array_key_exists('column', $field)) return $field['column'];
                else {
                    return isset($field['join']['local']) ? $field['join']['local'] : $field['join']['localColumn'];
                }
            }, array_filter($fields, function($field, $name) {
                if (array_key_exists('column', $field)) return true;
                if (isset($field['join'])) {
                    $join = $field['join'];
                    return array_key_exists('local', $join) || 
                           array_key_exists('localColumn', $join);
                }
            }, ARRAY_FILTER_USE_BOTH));
        }

        public function mapFieldToColumn($entity, $field) {
            $entityConfig = $this->getDefinition($entity);
            $columns = $this->getColumnMap($entity);

            if (array_key_exists($field, $columns)) {
                return $columns[$field];
            } else {
                throw new \Exception("Field {$field} does not exist in {$entity}.");
            }
        }

        public function mapColumnToField($entity, $column) {
            
        }

        public function getObjectReferenceCache() {
            return self::$_objectReferenceCache;
        }

        public function expr() {
            return new ExpressionBuilder();
        }
    }