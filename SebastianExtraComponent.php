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

            $context->templating = new SRender($this->getContext(), null, array_map(function($component) {
                return $component->getResourceUri('views', true);
            }, $components));

            if ($config->get('orm.enabled', false)) {
                $context->entityManager = new EntityManager($context, $config->sub('orm', []));
            }

            if ($config->get('form.enabled', true)) {
                //$context->formBuilder = function() {
                 //   print('hello'); die();
                //};

                $context->formBuilder = new FormBuilder($context, $config->sub('form'));
                if ($context->get('templating')) {
                    $context->get('templating')->addMacro('formRow', function($field) {
                        $field->render();
                    });
                }
            }
        }

        public function checkRequirements(ContextInterface $context) {
            return true;
        }
    }