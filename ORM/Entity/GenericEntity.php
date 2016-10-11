<?php
    namespace SebastianExtra\ORM\Entity;

    use Sebastian\Core\Entity\EntityInterface;

    class GenericEntity implements EntityInterface {
        protected $_fields = [];
        protected $definition;

        public function __construct(array $definition = null) {
            $this->definition = $definition;
        }

        public function __get($name) {
            $name[0] = strtolower($name[0]);
            return $this->_fields[$name] ?? null;
        }

        public function __set($name, $value) {
            $name[0] = strtolower($name[0]);
            $this->_fields[$name] = $value;
        }

        public function __call(string $name, array $arguments = [null]) {
            if (strpos($name, 'set') === 0) {
                $name = substr($name, 3);
                $this->$name = array_pop($arguments); 
            } else if (strpos($name, 'get') === 0) {
                $name = substr($name, 3);
                return $this->$name;
            }
        }

        public function __debugInfo() {
            return $this->_fields;
        }

        protected function shouldValidate() {
            return $this->definition && ($this->definition['validate'] ?? false) === true;
        }

        public function jsonSerialize() {
            return $this->_fields;
        }
    }