<?php
    namespace SebastianExtra\Form;

    use Sebastian\Core\Context\ContextInterface;
    use Sebastian\Utility\Configuration\Configuration;
    use Sebastian\Utility\Configuration\Loader\YamlLoader;
    use SebastianExtra\Constraint\Constraint;    
    use SebastianExtra\Form\Exception\FormBuilderException;
    use SebastianExtra\ORM\EntityManager;

    class FormBuilder {
        protected $form;
        protected $config;

        protected static $fieldTypes = [
            'select' => Field\SelectField::class,
            'textarea' => Field\TextAreaField::class,
            'input' => Field\InputField::class,
            'text' => Field\InputField::class,
            'number' => Field\NumberField::class,
            'password' => Field\PasswordField::class,
            'checkbox' => Field\CheckboxField::class,
            'file' => Field\FileField::class
        ];

        protected static $constraintTypes = [
            'not_blank' => Constraint\NotBlankConstraint::class
        ];

        public function __construct(ContextInterface $context, Configuration $config = null) {
            $this->config = $config ?: new Configuration();
            $this->config->extend([]);

            $this->loader = new YamlLoader($context);
            $this->cacheManager = $context->getCacheManager();
        }

        public function addFieldType($type, $class) {
            if (isset(self::$fieldTypes[$type]) && !$this->config->get('allow_field_override', false)) {
            } else {
                self::$fieldTypes[$type] = $class;
            }
        }

        public static function import($name, $fields) {
            $form = new Form($name);

            foreach ($config as $key => $field) {
                //$form->
            }
        }

        /**
         * sets the forms "action" parameter
         * @param string $action [description]
         */
        public function action($action) {
            $this->form->setAction($action);
            return $this;
        }

        public function add($name, $type, $value = null, $params = []) {
            if (!array_key_exists($type, self::$fieldTypes)) {
                throw new FormBuilderException("Field type {$type} does not exist.");
            } else {
                $field = new self::$fieldTypes[$type]($this->form, $name, $value, $params);
            }
            
            $this->form->addField($field);

            return $this;
        }

        /**
         * add form-level constraints
         * @param Constraint $constraint a form constraint
         */
        public function addFormConstraint(ConstraintInterface $constraint) {
            $this->form->addConstraint($constraint);
            return $this;
        }

        /**
         * add a field-level constraint
         * @param string     $field      the id of the field
         * @param Constraint $constraint a constraint
         */
        public function addFieldConstraint($field, ConstraintInterface $constraint) {
            if (!$form->hasField($field)) {
                throw new FormBuilderException("Form has no {$field} field.");
            }

            $this->form->addFieldConstraint($field, $constraint);
            return $this;
        }

        /**
         * sets a form attribute i.e. 'enc-type'
         * @param [type] $attribute [description]
         * @param [type] $value     [description]
         */
        public function attribute($attribute, $value) {
            $this->form->addAttribute($attribute, $value);
            return $this;
        }

        public function bind($modelClass, EntityManager $em) {
            $this->em = $em;
            $this->form->setRepository($em->getRepository($modelClass));

            return $this;
        }

        public function create($name = null, $defaults = []) {
            if (!$name || $name == "") {
                throw new FormBuilderException("Form name cannot be blank.");
            }

            $this->form = new Form(null, $name, null, [
                'enctype' => 'application/x-www-form-urlencoded'
            ]);
            
            return $this;
        }

        public function load($filename, array $defaults = []) {
            if ($this->cacheManager && $this->cacheManager->isCached($filename)) {
                return $this->cacheManager->load($filename);
            }

            $mConfig = $this->loader->load($filename, 'forms'); // todo validate response
            $name = $mConfig->key();

            if ($name == null) throw new \Exception("asdasd");

            $config = $mConfig->sub($name);
            $method = $config->get('method', Form::METHOD_POST);
            $attributes = $config->sub('attributes', []);
            $fields = $config->sub('fields', []);

            $this->create($name);
            $this->method($method);

            foreach ($attributes as $key => $value) {
                $this->attribute($key, $value);
            }

            foreach ($fields as $fieldName => $config) {
                $type = $config->get('type', 'group');

                if ($type === 'group') {
                    $field = new Form($this->form, $fieldName, null, $config);
                    //$field->setAttribute('mapped', $config->getAttribute)

                    foreach ($config['fields'] as $key => $mField) {
                        $mField = new self::$fieldTypes[$mField['type']]($field, $key, $mField['value'] ?? null, $mField['attributes'] ?? []);
                        $field->addField($mField, $mField->getName());
                    }

                    $this->form->addField($field);
                    continue;
                }

                $fieldAttributes = $config->get('attributes', []);
                $value = $config->get('value', null);

                if ($config->has('map')) {
                    $fieldAttributes['map'] = $fieldAttributes['map'] ?? $config->get('map');
                }

                $this->add($fieldName, $type, $value, $fieldAttributes);

                $constraints = $config->get('constraints', []);
                
                foreach ($constraints as $type => $fieldConfig) {
                    //$constraint = $this->getFieldConstraint($type, $fieldConfig);
                    //$this->addFieldConstraint($fieldName, $constraint);
                }
            }

            return $this;
        }

        public function method($method) {
            if (!in_array(strtoupper($method), ['GET', 'POST'])) {
                throw new FormBuilderException("Method must be one of GET or POST");
            }

            $this->form->setMethod($method);
            return $this;
        }

        public function getFieldConstraint($type, $config) {
            if (!isset($this->constraintTypes[$type])) {
                throw new FormBuilderException("no constraint {$type}");
            }

            return $this->constraintTypes[$type];
        }

        public function getForm() {
            if ($this->cacheManager) {
                
            }

            return $this->form;
        }
    }