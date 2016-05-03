<?php
    namespace SebastianExtra\Form\Exception;

    class FormConstraintException {
        protected $form;

        public function __construct(Form $form) {

        }
    }