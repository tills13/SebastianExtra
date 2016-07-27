<?php
    namespace SebastianExtra;

    use Sebastian\Core\Component\Component;
    use Sebastian\Core\Context\ContextInterface;
    use Sebastian\Utility\Configuration\Configuration;

    use SebastianExtra\EntityManager\EntityManager;
    use SebastianExtra\Form\FormBuilder;
    use SebastianExtra\Templating\SRender;

    class SebastianExtraComponent extends Component {
        public function __construct(ContextInterface $context, $name, Configuration $config = null) {
            parent::__construct($context, $name, $config);

            $this->setWeight(0);
        }

        public function setup(Configuration $config = null) {
            $context = $this->getContext();
            $config = !is_null($config) ? $config->sub('components.sebastian_extra') : $this->getConfig();
            $components = $context->getComponents();

            $context->templating = new SRender($this->getContext(), $config->sub('templating', []), array_map(function($component) {
                return $component->getResourceUri('views', true);
            }, $components));

            if ($config->get('orm.enabled', false)) {
                $context->entityManager = new EntityManager($context, $config->sub('orm', []));
            }

            if ($config->get('form.enabled', true)) {
                $context->formBuilder = new FormBuilder($context, $config->sub('form'));
                $this->setupFormTemplatingMacros();
            }
        }

        private function setupFormTemplatingMacros() {
             if ($templating = $this->getContext()->get('templating')) {
                $templating->addMacro('formRow', function($form, $field, $args = []) use ($templating) {
                    if (!$field instanceof Form\Field\FieldInterface) {
                        $field = $form->get($field);
                    }

                    return $templating->render('form_group', [
                        'form' => $form,
                        'field' => $field,
                    ]);
                });

                $templating->addMacro('formLabel', function($form, $field, $default = null) use ($templating) {
                    if (!$field instanceof Form\FormInterface) {
                        $field = $form->get($field);
                    }

                    return $field->getAttribute('label') ?? $field->getAttribute('placeholder') ?? $default ?? $field->getName();
                });
            }
        }

        public function checkRequirements(ContextInterface $context) {
            return true;
        }
    }