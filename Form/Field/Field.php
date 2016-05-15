<?php
    namespace SebastianExtra\Form\Field;

    use Sebastian\Utility\Collection\Collection;
    use SebastianExtra\Form\Constraint\FieldConstraint;
    use SebastianExtra\Form\Form;

    abstract class Field implements FieldInterface {
        protected $attributes;
        protected $constraints;
        protected $errors;
        protected $form;
        protected $name;
        protected $validated;
        protected $value;

        public function __construct(Form $form, $name, array $attributes = null) {
            $this->form = $form;
            $this->name = $name;
            $this->attributes = $attributes ? new Collection($attributes) : new Collection();

            $this->constraints = [];
            $this->errors = [];
            $this->validated = false;
        }

        public function setAttributes(Collection $attributes = null) {
            $this->attributes = $attributes ?: new Collection();
        }

        public function setAttribute($attribute, $value) {
            $this->attributes->set($attribute, $value);
        }

        public function getAttributes() {
            return $this->attributes;
        }

        public function getAttribute($attribute, $default = null) {
            return $this->attributes->get($attribute, $default);
        }

        public function getAttributesString() {
            $string = "";

            foreach ($this->getAttributes() as $key => $value) {
                $string .= "{$key}=\"{$value}\"";
            }

            return $string;
        }

        public function addConstraint(FieldConstraint $constraint) {
            $this->constraints[] = $constraint;
        }

        public function setConstraints(array $constraints) {
            $this->constraints = $constraints;
        }

        public function getConstraints() {
            return $this->constraints;
        }

        public function addError(FieldError $error) {
            $this->errors[] = $error;
        }

        public function setErrors(array $errors) {
            $this->errors = $errors;
        }

        public function hasErrors() : boolean {
            return $this->validated && $this->errors->count() != 0;
        }

        public function getErrors() {
            return $this->errors;
        }

        public function getForm() {
            return $this->form;
        }

        public function setId($id) {
            $this->setAttribute('id', $id);
        }

        public function getId() {
            if ($this->attributes->has('id')) {
                return $this->getAttribute('id');
            } else {
                return $this->getName();
            }
        }

        public function setName($name) {
            $this->name = $name;
        }

        public function getName() : string {
            return $this->name;
        }

        public function validate() {
            foreach ($this->getConstraints() as $constraint) {
                try {
                    $constraint->check();
                } catch (FieldConstraintException $e) {
                    $this->addError(new FieldError($e));
                }
            }

            $this->validated = true;
        }
    }