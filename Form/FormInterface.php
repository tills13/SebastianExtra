<?php 
    namespace SebastianExtra\Form;

    interface FormInterface {
        public function setName($name);
        public function getName() : string;
        public function setValue($value);
        public function getValue();
        public function hasErrors();
        public function render();
        public function validate();
    }