<?php
    namespace SebastianExtra\Templating\Macro;

    interface SRenderMacroInterface {
        public function getName();
        public function execute($arguments);
    }