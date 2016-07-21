<?php
    namespace SebastianExtra\Form;

    use \Exception;

    use Sebastian\Core\Model\EntityInterface;
    use Sebastian\Core\Http\Request;
    use Sebastian\Utility\Collection\Collection;
   
    use SebastianExtra\Form\Constraint\ConstraintInterface;
    use SebastianExtra\Form\Error\ErrorInterface;
    use SebastianExtra\Form\Exception\FormException;
    use SebastianExtra\Repository\Repository;

    class Form implements FormInterface {
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
        protected $parent;
        protected $repository;
        protected $submitted;
        protected $validated;
        protected $value;

        public function __construct(Form $parent = null, $name, array $attributes = []) {       
            $this->action = null;
            $this->attributes = $attributes ? new Collection($attributes) : new Collection();
            $this->constraints = new Collection();
            $this->errors = new Collection();
            $this->fields = new Collection();
            $this->method = self::METHOD_GET;
            $this->name = $name;
            $this->parent = $parent;
            $this->submitted = false;
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

        public function getAttribute($attribute, $default = null) {
            return $this->attributes->get($attribute, $default);
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

        public function addConstraint(ConstraintInterface $constraint) {
            $this->constraints->set(null, $constraint);
        }

        public function addFieldConstraint($field, ConstraintInterface $constraint) {
            $this->getField($field)->addConstraint($constraint);
        }

        public function setConstraints(ConstraintInterface ... $constraints) {
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

        public function addError(ErrorInterface $error) {
            $this->errors->set(null, $error);
        }

        public function addErrorFromException(Exception $e) {
            $this->addError(new FormError($this, $e));
        }

        public function setErrors(ErrorInterface ... $errors) {
            $this->errors = $errors;
        }

        public function hasErrors() {
            return $this->validated && $this->errors->count() != 0;
        }

        public function getErrors() {
            return $this->errors;
        }

        public function addField(FormInterface $field) {
            $this->fields->set($field->getName(), $field);
        }

        public function setFields(FormInterface ... $fields) {
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

        public function getName() : string {
            return $this->name;
        }

        public function getFullName() : string {
            return $this->getParent() ? $this->getParent()->getFullName() . "[{$this->getName()}]" : $this->getName();
        }

        public function getParent() {
            return $this->parent;
        }

        public function setRepository(Repository $repository) {
            $this->repository = $repository;
        }

        public function getRepository() : Repository {
            return $this->repository;
        }

        public function setValue($value) {
            $this->value = $value;
        }

        public function getValue() {
            return $this->value;
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
            $method = $request->method();
            $name = $this->getName();
            $data = [];

            if ($this->getMethod() != $method) {
                return;
            }

            if ($method == 'GET' || $method == 'HEAD') {
                if ($this->getName() == '') {
                    $data = $request->params;
                } else {
                    if (!$request->has($name)) return;
                    $data = $request->get($name);
                }
            } else { // post
                // todo handle files?
                if ($this->getName() == '') {
                    $data = $request->params;
                } else {
                    if (!$request->has($name)) return;
                    $data = $request->get($name);
                }
            }

            $this->submit($data);
        }

        public function isSubmitted() {
            return $this->isSubmitted;
        }

        public function submit(array $data = []) {
            foreach ($this->getFields() as $name => $field) {
                $isSubmitted = array_key_exists($field->getName(), $data);
                $mapped = $field->getAttribute('mapped', true);
                $mappedField = $field->getAttribute('map') ?? $name;

                if (!$isSubmitted && $field instanceof Field\CheckboxField) {
                    $value = false;
                } else {
                    $value = $data["{$field->getName()}"];
                }

                $field->setValue($value);

                if (!is_null($this->entity) && !is_null($this->repository) && $mapped) {
                    $this->entity = $this->repository->setFieldValue($this->entity, $mappedField, $field->getValue());
                }
            }

            $this->validate();
            $this->isSubmitted = true;
        }

        public function isValid() {
            return $this->validated && !$this->hasErrors();
        }

        public function start() {
            $attrs = $this->getAttributesString();
            return "<form action=\"{$this->getAction()}\" method=\"{$this->getMethod()}\" {$attrs}>";
        }

        public function render() {
            foreach ($this->getFields() as $field) {
                echo $field->render();
            }
        }

        public function validate() {
            foreach ($this->getConstraints() as $constraint) {
                try {
                    $constraint->validate(); 
                } catch (ConstraintException $e) {
                    $this->addError(new FormError($this, $e));
                }
            }

            foreach ($this->getFields() as $field) {
                $field->validate();
            }

            $this->validated = true;
        }
    }