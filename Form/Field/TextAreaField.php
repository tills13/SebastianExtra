<?php
    namespace SebastianExtra\Form\Field;

    use SebastianExtra\Form\Form;

    class TextAreaField extends Form {
        public function setValue($value) {
            $this->value = $value;
        }

        public function getValue() {
            return $this->value;
        }

        public function render() {
            $attrs = $this->getAttributesString();
            return "<textarea type=\"text\" name=\"{$this->getFullName()}\" {$attrs}>{$this->getValue()}</textarea>";
        }
    }