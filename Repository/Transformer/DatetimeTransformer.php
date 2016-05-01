<?php
    namespace SebastianExtra\Repository\Transformer;

    class DatetimeTransformer extends BaseTransformer {
        protected $name; 

        public function __construct() {
            $this->setName('timestamp');
        }

        public function transform($value) {
            return new \DateTime($value);
        }

        public function reverseTransform($value) {
            if ($value == null) return null;
            //if (!$value instanceof \DateTime) throw new TransformException();
            return $value->format('Y-m-d G:i:s');
        }
    }