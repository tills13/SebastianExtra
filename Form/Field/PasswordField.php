<?php
    namespace SebastianExtra\Form\Field;

    class PasswordField extends InputField {
        public function render() {
            $attrs = "";
            return "<input type=\"password\" name=\"{$this->getName()}\" {$attrs} value=\"{$this->getValue()}\">";
        }
    }