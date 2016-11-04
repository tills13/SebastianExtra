<?php
    namespace SebastianExtra\Form;

    use \Exception;

    use Sebastian\Core\Entity\EntityInterface;
    use Sebastian\Core\Http\Request;
    use Sebastian\Utility\Collection\Collection;
   
    use SebastianExtra\Form\Constraint\ConstraintInterface;
    use SebastianExtra\Form\Error\ErrorInterface;
    use SebastianExtra\Form\Exception\FormException;
    use SebastianExtra\ORM\Repository\Repository;

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

        public function __construct(Form $parent = null, $name, $value = null, $attributes = []) {       
            $this->action = null;

            if (is_array($attributes)) $this->attributes = new Collection($attributes);
            else $this->attributes = $attributes ?? new Collection();

            $this->constraints = [];
            $this->errors = [];
            $this->fields = [];
            $this->method = self::METHOD_GET;
            $this->name = $name;
            $this->parent = $parent;
            $this->submitted = false;
            $this->validated = false;

            $this->setValue($value);
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
            $this->constraints[] = $constraint;
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
            else {
                $data = [];

                foreach ($this->getFields() as $name => $field) {
                    $data[$name] = $field->getValue() ?? $field->getData();
                }
            }

            return $data;
            // todo serialize form
        }

        public function addError(ErrorInterface $error) {
            $this->errors[] = $error;
        }

        public function addErrorFromException(Exception $e) {
            $this->addError(new FormError($this, $e));
        }

        public function setErrors(ErrorInterface ... $errors) {
            $this->errors = $errors;
        }

        public function hasErrors() {
            return $this->validated && count($this->errors) != 0;
        }

        public function getErrors() {
            return $this->errors;
        }

        public function addField(FormInterface $field) {
            $this->fields[$field->getName()] = $field;
        }

        public function setFields(FormInterface ... $fields) {
            $this->fields = $fields;
        }

        public function hasField($field) {
            $name = $field instanceof Form ? $field->getName() : $field;
            return isset($this->fields[$name]);
        }

        public function getField(string $field) {
            if (!$this->hasField($field)) {
                throw new FormException("Field {$field} does not exist...");
            }

            return $this->fields[$field];
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
                    $value = $data["{$field->getName()}"] ?? $field->getValue();
                }

                if ($mapped) $field->setValue($value);
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