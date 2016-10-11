<?php
    namespace SebastianExtra\Assets\Listener;

    use Sebastian\Core\Event\Event;
    use Sebastian\Core\Http\Request;
    
    use Sebastian\Utility\Configuration\Configuration;

    class OnBeforeRequestListener {
        protected $config;

        public function __construct(Configuration $config) {
            $this->config = $config->extend([

            ]);
        }

        public function onBeforeRequest(Request $request, Event $event) {
            die("here");   
        }
    }