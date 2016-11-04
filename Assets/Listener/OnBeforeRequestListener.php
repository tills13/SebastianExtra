<?php
    namespace SebastianExtra\Assets\Listener;

    use \Exception;
    use \RecursiveDirectoryIterator;
    use \RecursiveIteratorIterator;
    use \FilesystemIterator;

    use Sebastian\Core\Event\Event;
    use Sebastian\Core\Http\Request;
    use Sebastian\Core\Context\ContextInterface;
    
    use Sebastian\Utility\Configuration\Configuration;

    class OnBeforeRequestListener {
        protected $config;
        protected $context;
        protected $filters = [
            'sass' => 'compileSass'
        ];

        public function __construct(ContextInterface $context, Configuration $config) {
            $this->context = $context;
            $this->config = $config->extend([

            ]);
        }

        public function onBeforeRequest(Request $request, Event $event) {
            foreach($this->config->get('filters', []) as $index => $filter) {
                if (($mFilter = $this->filters[$filter['type']] ?? null) != null) {
                    //$filterArguments = Injector::resolveCallable([$this, $mFilter], );

                    $success = call_user_func([$this, $mFilter], $filter); 
                }
            }
        }

        public function compileSass($parameters) {
            $input = $parameters['input'] ?? null;
            $output = $parameters['output'] ?? null;

            $components = array_change_key_case($this->context->getComponents(), CASE_LOWER);

            // resolve input path
            $input = preg_replace_callback('/\$\{([^\}]*)\}/', function($match) use ($components) {
                $name = strtolower($match[1]);

                if (isset($components[$name])) {
                    return implode(DIRECTORY_SEPARATOR, [
                        $components[$name]->getComponentDirectory(false),
                        "Resources"
                    ]);
                }

                return "";
            }, $input);

            if (!file_exists($input) || is_dir($input)) {
                throw new Exception("Sebastian Assets: input file does not exist or is a directory.");
            }

            // resolve output path
            $output = preg_replace_callback('/\$\{([^\}]*)\}/', function($match) {
                $name = strtolower($match[1]);

                if ($name === 'webdirectory') {
                    return $this->getWebDirectory() ?? "";
                }
            }, $output);

            $input = $this->getAbsolutePath($input);
            $output = $this->getAbsolutePath($output);

            if (!$this->shouldRecompile($input, $output)) {
                return;
            }

            if (!file_exists(dirname($output))) {
                $success = mkdir(dirname($output), 0777, true);

                if (!$success) {
                    throw new Exception("Sebastian Assets: could not create output directory " . dirname($output));
                }
            }

            print ("running: sass {$input} > {$output}");
            exec("sass {$input} > {$output}", $output, $return);

            if ($return === 0) {
                
            } else {
                throw new Exception("Something went wrong... " . implode("\n", $output));
            }
        }

        private function shouldRecompile($input, $output) {
            $shouldRecompile = false;
            $cachedFiles = [];

            $inputDirectory = dirname($input);
            $outputDirectory = dirname($output);

            $cacheDirectory = $this->getAbsolutePath(implode(DIRECTORY_SEPARATOR, [$outputDirectory, "..", ".sebastian-assets-cache"]));

            if (!file_exists($cacheDirectory)) {
                if (!mkdir($cacheDirectory, 0777, true)) {
                    throw new Exception("Sebastian Assets: could not create asset cache directory");
                }

                $shouldRecompile = true;
            } else {
                $iterator = new RecursiveDirectoryIterator($inputDirectory, FilesystemIterator::SKIP_DOTS);
                $iterator = new RecursiveIteratorIterator($iterator);

                foreach($iterator as $i) {
                    //$fileNameHash = hash('md5', $i->getPathName());
                    //$fileContentHash = hash('md5', file_get_contents($i->getPathName()));

                    //$cached = implode(DIRECTORY_SEPARATOR, [$cacheDirectory, ".{$fileNameHash}{$fileContentHash}"]);
                    
                    //if (!file_exists($cached)) $shouldRecompile = true;
                    //$success = touch($cached);
                    //$cached[] = $cached;
                }
            }

            return $shouldRecompile;
        }

        private function getAbsolutePath(string $path = __DIR__, $absolute = true) {
            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
            $absolutes = [];

            foreach ($parts as $part) {
                if ($part === '.') continue;
                else if ($part === '..') array_pop($absolutes);
                else $absolutes[] = $part;
            }

            return ($absolute ? DIRECTORY_SEPARATOR : "") . implode(DIRECTORY_SEPARATOR, $absolutes);
        }

        private function getWebDirectory() {
            $globalConfig = $this->context->getConfig();
            $webDirectory = $globalConfig->get('application.web_directory', implode(DIRECTORY_SEPARATOR, [\APP_ROOT, '..', 'web']));

            return $webDirectory;
        }
    }