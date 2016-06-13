<?php
    namespace SebastianExtra\Form\Constraint;

    use SebastianExtra\Form\Field\Field;
    use SebastianExtra\Form\Constraint\ConstraintInterface;

    /**
     * NotBlankConstraint
     *
     * @author Tyler <tyler@sbstn.ca>
     * @since Oct. 2015
     */
    abstract class FieldConstraint implements ConstraintInterface {
        protected $field;
                
        public function __construct(Field $field) {
            $this->field = $field;
        }
    }