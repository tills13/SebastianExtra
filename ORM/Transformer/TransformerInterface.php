<?php
    namespace SebastianExtra\ORM\Transformer;

    interface TransformerInterface {
        public function transform($value);
        public function reverseTransform($value);
    }