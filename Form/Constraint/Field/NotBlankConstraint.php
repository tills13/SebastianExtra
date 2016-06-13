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
    class NotBlankConstraint extends FieldConstraint {
        public function validate() {
            if (empty($this->field->getValue())) {
                //throw new NotBlankException($this->getFormPart());
            }
        }
    }