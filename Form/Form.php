<?php
    namespace SebastianExtra\Form;


    use Sebastian\Core\Model\EntityInterface;
    use Sebastian\Core\Http\Request;
    use Sebastian\Utility\Collection\Collection;
    use SebastianExtra\Form\Field\FieldInterface;
    use SebastianExtra\Repository\Repository;

    class Form {
        const METHOD_GET = "GET";
        const METHOD_POST = "POST";

        protected $action;
        protected $attributes;
        protected $constraints;
        protected $entity;
        protected $errors;
        protected $fields;
        protected $method;
        protected $name;
        protected $repository;
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

        public function getData() {
            if ($this->entity) return $this->entity;
            // todo serialize form
        }

        /**
         * sets the forms data
         * if entity, then bind the entity
         * if array, then bind the array values
         * 
         * @param mixed $data the data to bind to the form
         */
        public function setData($data) {
            // todo
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

        public function setRepository(Repository $repository) {
            $this->repository = $repository;
        }

        public function getRepository() : Repository {
            return $this->repository;
        }

        public function bind(Request $request) {
            foreach ($this->getFields() as $field) {
                $mapped = $field->getAttribute('mapped', true);
                $mappedField = $field->getAttribute('map') ?? $field->getName();

                if (!is_null($this->entity)) {
                    $value = $request->get("{$field->getName()}", null);
                    $value = $mapped ? $value ?? $this->repository->getFieldValue($this->entity, $mappedField)
                                     : $value; // I think that's right...
                } else {
                    $value = $request->get("{$field->getName()}");
                }
                
                $field->setValue($value);

                if (!is_null($this->entity) && !is_null($this->repository) && $mapped) {
                    $this->entity = $this->repository->setFieldValue($this->entity, $mappedField, $field->getValue());
                }
            }
        }

        public function bindModel(EntityInterface $entity) {
            $this->entity = $entity;

            foreach ($this->getFields() as $field) {
                if (!$field->getAttribute('mapped', true)) continue;

                $mField = $field->getAttribute('map') ?? $field->getName();
                $value = $this->repository->getFieldValue($entity, $mField);
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