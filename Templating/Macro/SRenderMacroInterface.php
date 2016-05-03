<?php
    namespace SebastianExtra\Templating\Macro;

    interface SRenderMacroInterface {
        //public function __construct();
        public function getName();
        public function execute($arguments);
    }