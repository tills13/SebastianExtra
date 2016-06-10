<?php
    namespace SebastianExtra\Form\Field;

    class CheckboxField extends Field {
        public function setValue($value = false) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $this->value = $value;
        }

        public function getValue() {
            return $this->value;
        }

        /**
         * coerce $this->getValue by comparing it
         * to a boolean 
         * @return boolean [description]
         */
        public function isChecked() : bool {
            return $this->getValue() == true;
        }

        public function render() {
            $attrs = $this->getAttributesString();
            return "<input type=\"checkbox\" name=\"{$this->getFullName()}\" {$attrs}" . ($this->isChecked() ? " checked" : "" ). ">";
        }
    }