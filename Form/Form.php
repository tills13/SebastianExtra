<?php
    namespace SebastianExtra\Form;

    use Sebastian\Utility\Collection\Collection;

    class Form {
        const METHOD_GET = "GET";
        const METHOD_POST = "POST";

        protected $attributes;
        protected $action;
        protected $constraints;
        protected $errors;
        protected $fields;
        protected $method;
        protected $name;

        public function __construct($name) {
            $this->name = $name;
            $this->attributes = new Collection();
            $this->action = null;
            $this->method = self::METHOD_GET;
            $this->constraints = new Colleciton();
            $this->fields = new Colleciton();
        }

        public function setAction($action) {
            $this->action = $action;
        }

        public function getAction() {
            return $this->action;
        }
    }