<?php
    namespace SebastianExtra\Form\Error;

    class FormError implements ErrorInterface {
        protected $exception;

        public function __construct(FormConstraintException $exception) {
            $this->exception = $exception;
        }

        public function getException() {
            return $this->exception;
        }

        public function getMessage() {
            return $this->exception->getMessage();
        }
    }