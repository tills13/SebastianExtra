<?php
    namespace SebastianExtra\Form;

    use Sebastian\Core\Http\Request;
    use Sebastian\Utility\Collection\Collection;
    use SebastianExtra\Form\Field\FieldInterface;

    class Form {
        const METHOD_GET = "GET";
        const METHOD_POST = "POST";

        protected $action;
        protected $attributes;
        protected $constraints;
        protected $errors;
        protected $fields;
        protected $method;
        protected $name;
        protected $validated;

        public function __construct($name) {           
            $this->action = null;
            $this->attributes = new Collection();
            $this->constraints = new Collection();
            $this->errors = new Collection();
            $this->fields = new Collection();
            $this->method = self::METHOD_GET;
            $this->name = $name;
            $this->validated = false;
        }

        public function setAction($action) {
            $this->action = $action;
        }

        public function getAction() {
            return $this->action;
        }

        public function addAttribute($attribute, $value) {
            $this->attributes->set($attribute, $value);
        }

        public function setAttributes(Collection $attributes = null) {
            $this->attributes = $attributes;
        }

        public function getAttributes() {
            return $this->attributes;
        }

        public function getAttributesString() {
            $string = "";

            foreach ($this->getAttributes() as $key => $value) {
                $string .= "{$key}=\"{$value}\"";
            }

            return $string;
        }

        public function addConstraint(FormConstraint $constraint) {
            $this->constraints->set(null, $constraint);
        }

        public function addFieldConstraint($field, FieldConstraint $constraint) {
            $this->getField($field)->addConstraint($constraint);
        }

        public function setConstraints(Collection $constraints = null) {
            $this->constraints = $constraints;
        }

        public function getConstraints() {
            return $this->constraints;
        }

        public function addError(FormError $error) {
            $this->errors->set(null, $error);
        }

        public function setErrors(Collection $errors = null) {
            $this->errors = $errors;
        }

        public function getErrors() {
            return $this->errors;
        }

        public function addField(FieldInterface $field) {
            $this->fields->set($field->getName(), $field);
        }

        public function setFields(Collection $fields = null) {
            $this->fields = $fields;
        }

        public function hasField($field) {
            return $this->fields->has($field);
        }

        public function getField($field) {
            if (!$this->hasField($field)) {
                throw new FormException("Field {$field} does not exist...");
            }

            return $this->fields->get($field);
        }

        public function getFields() {
            return $this->fields;
        }

        public function setMethod($method) {
            $this->method = $method;
        }

        public function getMethod() {
            return $this->method;
        }

        public function setName($name) {
            $this->name = $name;
        }

        public function getName() {
            return $this->name;
        }

        public function bind(Request $request) {
            foreach ($this->getFields() as $field) {
                $value = $request->get("{$field->getName()}");
                $field->setValue($value);
            }
        }

        public function end() {
            return "<form/>";
        }

        /**
         * convenience method for getting fields
         * @param  [type]
         * @return [type]
         */
        public function get($field) {
            return $this->getField($field);
        }

        public function handleRequest(Request $request) {
            $this->bind($request);
            $this->validate();
        }

        public function isValid() {
            return $this->validated && $this->getErrors()->count() == 0;
        }

        public function start() {
            $attrs = $this->getAttributesString();
            return "<form action=\"{$this->getAction()}\" method=\"{$this->getMethod()}\" {$attrs}>";
        }

        public function validate() {
            foreach ($this->getConstraints() as $constraint) {
                try {
                    $constraint->validate(); 
                } catch (FormConstraintException $e) {
                    $this->addError(new FormError($e));
                }
            }

            foreach ($this->getFields() as $field) {
                $field->validate();
            }

            $this->validated = true;
        }
    }