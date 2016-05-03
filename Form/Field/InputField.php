<?php
    namespace SebastianExtra\Form\Field;

    class InputField extends Field {
        public function setValue($value) {
            $this->value = $value;
        }

        public function getValue() {
            return $this->value;
        }

        public function render() {
            $attrs = "";
            return "<input type=\"text\" name=\"{$this->getName()}\" {$attrs} value=\"{$this->getValue()}\">";
        }
    }