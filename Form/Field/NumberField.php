<?php
    namespace SebastianExtra\Form\Field;

    class NumberField extends InputField {
        public function setMin($min) {
            $this->setAttribute("min", $min);
        }

        public function setMax($max) {
            $this->setAttribute("max", $max);
        }

        public function setValue($value) {
            $this->value = $value;
        }

        public function getValue() {
            return $this->value;
        }

        public function render() {
            $attrs = $this->getAttributesString();
            return "<input type=\"number\" name=\"{$this->getFullName()}\" {$attrs} value=\"{$this->getValue()}\">";
        }
    }