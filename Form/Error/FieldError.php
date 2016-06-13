<?php
    namespace SebastianExtra\Form\Error;

    use \Exception;
    use SebastianExtra\Form\Field\Field;

    class FormError implements ErrorInterface {
        protected $exception;
        protected $field;

        public function __construct(Field $field, Exception $exception) {
            $this->field = $field;           
            $this->exception = $exception;
        }

        public function getException() {
            return $this->exception;
        }

        public function getMessage() {
            return $this->exception->getMessage();
        }
    }