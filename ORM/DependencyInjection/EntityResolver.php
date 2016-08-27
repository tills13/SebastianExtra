<?php
    namespace SebastianExtra\ORM\DependencyInjection;

    use \ReflectionParameter;
    
    use Sebastian\Core\DependencyInjection\Injector;
    use Sebastian\Core\DependencyInjection\Resolver\ResolverInterface;
    use Sebastian\Core\Entity\EntityInterface;
    use Sebastian\Core\Entity\UserInterface;

    use SebastianExtra\ORM\EntityManager;

    class EntityResolver implements ResolverInterface {
        protected $em;

        public function __construct(EntityManager $em) {
            $this->em = $em;
        }

        public function canResolve(ReflectionParameter $symbol) : bool {
            $class = $symbol->getClass();
            return $class && $class->implementsInterface(EntityInterface::class);
        }

        public function resolve(Injector $injector, ReflectionParameter $symbol) {
            $name = $symbol->getName();
            $class = $symbol->getClass()->getShortName();

            $repo = $this->em->getRepository($class);
            $dependency = $repo->get($injector->getDependency($name));

            return $dependency;
        }
    }