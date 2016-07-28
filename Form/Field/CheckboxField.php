<?php
    namespace SebastianExtra\Form\Field;

    use SebastianExtra\Form\Form;

    class CheckboxField extends Form {
        public function setValue($value = false) {
            if ($value == 'checked' || $value == 'on') $value = true;

            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $this->value = $value ?? false;
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