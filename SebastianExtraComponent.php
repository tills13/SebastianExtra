<?php
    namespace SebastianExtra;

    use Sebastian\Core\Component\Component;
    use Sebastian\Core\Context\ContextInterface;
    use Sebastian\Core\Event\Event;
    use Sebastian\Core\Event\EventBus;
    use Sebastian\Core\Http\Response\Response;
    use Sebastian\Utility\Configuration\Configuration;

    use SebastianExtra\EntityManager\EntityManager;
    use SebastianExtra\Form\FormBuilder;
    use SebastianExtra\Templating\SRender;

    class SebastianExtraComponent extends Component {
        public function __construct(ContextInterface $context, $name, Configuration $config = null) {
            parent::__construct($context, $name, $config);
        }

        public function setup() {
            $context = $this->getContext();
            $config = $this->getConfig();
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

                $templating->addMacro('formLabel', function($form, $field, $default = null) {
                    if (!$field instanceof Form\FormInterface) {
                        $field = $form->get($field);
                    }

                    return $field->getAttribute('label') ?? $field->getAttribute('placeholder') ?? $default ?? $field->getName();
                });

                $templating->addMacro('progressBar', function($parameters) use ($templating) {
                    if (isset($parameters['class'])) {
                        if (is_array($parameters['class'])) {
                            $parameters['class'] = implode(' ', $parameters['class']);
                        }
                    } else {
                        $parameters['class'] = '';
                    }

                    return $templating->render('progress_bar', $parameters);
                });

                EventBus::register(Event::VIEW, function(Event $event = null) {
                    if (!($responseContent = $event->getResponse()) instanceof Response) {
                        $response = new Response($responseContent);
                        $response->sendHttpResponseCode(Response::HTTP_OK);
                        $event->setResponse($response);
                    }
                });
            }
        }

        public function checkRequirements(ContextInterface $context) {
            return true;
        }
    }