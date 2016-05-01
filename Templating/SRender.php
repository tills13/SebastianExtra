<?php
	namespace SebastianExtra\Templating;

	use Sebastian\Core\Context\ContextInterface;
	use SebastianExtra\Templating\Exception\RenderException;

	class SRender {
		protected $context;

		protected $templateDirs;
		protected $validTemplateExtensions = ['.php'];

		protected $master;
		protected $blocks;
		protected $extensions;

		protected $blockContext;
		protected $templateContext;

		public function __construct(ContextInterface $context, $master = 'master.php', $templateDirs = []) {
			$this->context = $context;
			$this->templateDirs =  $templateDirs;//__DIR__ . DIRECTORY_SEPARATOR . "templates";
			$this->master = $master;
			$this->blocks = [];
			$this->extensions = [];
			$this->blockContext = [];
		}

		public function addTemplateDirectories(array $directories = []) {
			foreach ($directories as $directory) {
				$this->addTemplateDirectory($directory);
			}
		}

		public function addTemplateDirectory($directory = null) {
			if (!$directory) return false;
			if (!file_exists($directory)) throw new TemplateException("Template directory {$directory} does not exist");

			if (!in_array($directory, $this->templateDirs)) {
				$this->templateDirs[] = $directory;	
			}
		}

		public function block($name = null, $data = null) {
			if ($name == null) throw new RenderException('block name cannot be blank');

			if (!$data) {
				array_push($this->blockContext, $name);
				$this->start();
			} else {
				$this->blocks[$name] = $data;
			}
		}

		public function endBlock() {
			if (count($this->blockContext) == 0) {
				throw new RenderException("endBlock called before block", 1);
			}
			
			$context = array_pop($this->blockContext);
			$this->blocks[$context] = $this->stop();
		}

		public function embed($block = null, $default = null, $die = true) {
			if ($die && !isset($this->blocks[$block])) {
				throw new RenderException("Block {$block} doesn't exist...");
			}
			
			return !isset($this->blocks[$block]) ? $default : $this->blocks[$block];
		}

		public function import($template, $data = []) {
			return $this->render($template, $data);
		}

		public function extend($template) {
			if ($template == null) {
				throw new RenderException("Must specify a template extension", 1);
			}

			$this->extensions[$this->templateContext] = $template;
		}
		
		public function getTemplatePath($template) {
			$finalTemplate = $template . ".php";
			
			foreach ($this->templateDirs as $directory) {
				$path = implode(DIRECTORY_SEPARATOR, [$directory, $finalTemplate]);
				if (file_exists($path)) return $path;
			}
			
			$directories = implode(', ', $this->templateDirs);
			throw new RenderException("Template {$template} not found... Checked [{$directories}]", 1);	
		}

		public function render($template, $data = []) {
			$this->templateContext = $template;
			$path = $this->getTemplatePath($template);

			$this->start();

			foreach ($data as $key => $value) $$key = $value;
			
			$router = $this->context->getRouter();
			$request = $this->context->getRequest();
			$session = $request->getSession();

			include $path;

			$rendered = $this->stop();

			if (isset($this->extensions[$template])) {
				$extend = $this->extensions[$template];
				return $this->render($extend, $data);
			}

			//if (count($this->blockContext) != 0) {
			//	throw new Exception("endBlock not called after block");//RenderException()
			//}

			return $rendered;
		}

		public function start() {
			ob_start();
		}

		public function stop() {
			$ob = ob_get_clean();
			return $ob;
		}
	}