<?php 
    namespace SebastianExtra\Form\Field;

    interface FieldInterface {
        public function setName($name);
        public function getName() : string;
        public function setValue($value);
        public function getValue();
        public function hasErrors() : boolean;
        public function render();
        public function validate();
    }