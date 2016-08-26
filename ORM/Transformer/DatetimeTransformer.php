<?php
    namespace SebastianExtra\ORM\Transformer;

    use \DateTime;
    use SebastianExtra\ORM\Transformer\Exception\TransformException;

    class DatetimeTransformer extends BaseTransformer {
        protected $name; 

        public function __construct() {
            $this->setName('timestamp');
        }

        public function transform($value) {
            if ($value instanceof DateTime) return $value;
            else if (is_object($value)) throw new TransformException();
            return new DateTime($value);
        }

        public function reverseTransform($value) {
            if ($value == null) return null;
            return $value->format('Y-m-d G:i:s');
        }
    }