<?php
    namespace SebastianExtra\Form\Field;

    use SebastianExtra\Form\Form;

    class InputField extends Form {
        public function setValue($value) {
            $this->value = $value;
        }

        public function getValue() {
            return $this->value;
        }

        public function render() {
            $attrs = $this->getAttributesString();
            return "<input type=\"text\" name=\"{$this->getFullName()}\" {$attrs} value=\"{$this->getValue()}\">";
        }
    }