<?php
    namespace SebastianExtra;

    use Sebastian\Core\Component\Component;
    use Sebastian\Core\Context\ContextInterface;
    use Sebastian\Utility\Configuration\Configuration;

    use SebastianExtra\EntityManager\EntityManager;
    use SebastianExtra\Templating\SRender;

    class SebastianExtraComponent extends Component {
        public function __construct(ContextInterface $context, $name, Configuration $config = null) {
            parent::__construct($context, $name, $config);

            $this->setWeight(0);
        }

        public function setup() {
            $context = $this->getContext();
            $config = $this->getConfig();
            $components = $context->getComponents();

            $context->templating = new SRender($this->getContext(), null, array_map(function($component) {
                return $component->getResourceUri('views', true);
            }, $components));

            if ($config->get('orm.enabled', false)) {
                $context->entityManager = new EntityManager($context, $config->sub('orm', []));
            }
        }

        public function checkRequirements(ContextInterface $context) {
            return true;
        }
    }