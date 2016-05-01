<?php
	namespace SebastianExtra\Repository\Transformer;

	interface TransformerInterface {
		public function transform($value);
		public function reverseTransform($value);
	}