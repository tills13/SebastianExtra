<?php
    namespace SebastianExtra\Form\Error;

    use \Exception;
    use SebastianExtra\Form\Form;

    class FormError implements ErrorInterface {
        protected $exception;
        protected $form;

        public function __construct(Form $form, Exception $exception) {
            $this->form = $form;
            $this->exception = $exception;
        }

        public function getException() {
            return $this->exception;
        }

        public function getMessage() {
            return $this->exception->getMessage();
        }
    }