<?php
    namespace SebastianExtra\ORM\Transformer;

    class ArrayTransformer extends BaseTransformer {
        protected $name; 

        public function __construct() {
            $this->setName('array');
        }

        public function transform($value) {
            return explode(',', substr($value, 1, strlen($value) - 2));
        }

        public function reverseTransform($value) {
            if ($value == null) return null;
            return "{" . implode(',', $value) . "}";
        }
    }