<?php
    namespace SebastianExtra\Form;

    use SebastianExtra\Constraint\Constraint;
    use SebastianExtra\Exception\FormBuilderException;

    class FormBuilder {
        protected $form;
        protected $config;

        protected static $fieldTypes = [
            'select' => Field\SelectField::class,
            'textarea' => Field\TextAreaField::class,
            'input' => Field\InputField::class,
            'text' => Field\InputField::class,
            'password' => Field\PasswordField::class,
            'checkbox' => Field\CheckboxField::class
        ];

        public function __construct(Configuration $config = null) {
            $this->config = $config ?: new Configuration();
            $this->config->extend([]);
        }

        public function addFieldType($type, $class) {
            if (isset(self::$fieldTypes[$type]) && !$this->config->get('allow_field_override', false)) {
            } else {
                self::$fieldTypes[$type] = $class;
            }
        }

        public static function importForm(Configuration $config) {}

        /**
         * sets the forms "action" parameter
         * @param string $action [description]
         */
        public function action($action) {
            $this->form->setAction($action);
            return $this;
        }

        public function add($name, $type, $params) {
            if (!array_key_exists($type, self::$fieldTypes)) {
                throw new FormBuilderException("Field type {$type} does not exist.");
            }

            $field = new self::$fieldTypes[$type]($this->form, $name, $params);
            $this->form->addField($field);
            return $this;
        }

        /**
         * add form-level constraints
         * @param Constraint $constraint a form constraint
         */
        public function addFormConstraint(Constraint $constraint) {
            $this->form->addConstraint($constraint);
            return $this;
        }

        /**
         * add a field-level constraint
         * @param string     $field      the id of the field
         * @param Constraint $constraint a constraint
         */
        public function addFieldConstraint($field, Constraint $constraint) {
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
            $this->form->setAttribute($attribute, $value);
            return $this;
        }

        public function method($method) {
            if (!in_array(strtoupper($method), ['GET', 'POST'])) {
                throw new FormBuilderException("Method must be one of GET or POST");
            }

            $this->form->setMethod($method);
            return $this;
        }

        public function getForm() {
            return $this->form;
        }
    }