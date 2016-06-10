<?php
    namespace SebastianExtra\Form\Field;

    class PasswordField extends InputField {
        public function render() {
            $attrs = $this->getAttributesString();
            return "<input type=\"password\" name=\"{$this->getFullName()}\" {$attrs} value=\"{$this->getValue()}\">";
        }
    }