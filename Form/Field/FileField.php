<?php
    namespace SebastianExtra\Form\Field;

    use SebastianExtra\Form\Form;

    class FileField extends Form {
        public function __construct(Form $parent = null, $name, $value = null, $attributes = []) {
            parent::__construct($parent, $name, $value, $attributes);

            if ($parent) {
                $parent->setMethod(Form::METHOD_POST);
                $parent->addAttribute('enctype', 'multipart/form-data');
            }
        }

        public function setValue($value) {
            $this->value = $value;
        }

        public function getValue() {
            return $this->value;
        }

        public function save() {
            //$this->parent->getRequest()
            
        }

        public function render() {
            $attrs = $this->getAttributesString();
            return "<input type=\"file\" name=\"{$this->getFullName()}\" {$attrs} value=\"{$this->getValue()}\">";
        }
    }