<?php
	namespace SebastianExtra\Repository\Transformer;

	abstract class BaseTransformer implements TransformerInterface {
		protected $name;
		public function __construct() {}

		abstract public function transform($value);
		abstract public function reverseTransform($value);

		public function getName() {
			return $this->name;
		}

		public function setName($name) {
			$this->name = $name;
		}
	}