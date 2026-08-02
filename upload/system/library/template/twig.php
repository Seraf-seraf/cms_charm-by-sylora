<?php
namespace Template;
final class Twig {
	private $data = array();

	public function set($key, $value) {
		$this->data[$key] = $value;
	}
	
	public function render($filename, $code = '') {
		if (!$code) {
			$file = DIR_TEMPLATE . $filename . '.twig';

			if (is_file($file)) {
				$code = file_get_contents($file);
			} else {
				throw new \Exception('Error: Could not load template ' . $file . '!');
				exit();
			}
		}

		// initialize Twig environment
		$config = array(
			'autoescape'  => false,
			'debug'       => false,
			'auto_reload' => true,
			'cache'       => DIR_CACHE . 'template/'
		);

		try {
			$main_loader = new \Twig\Loader\ArrayLoader(array($filename . '.twig' => $code));
			$template_root = $this->getTemplateRoot($filename);
			$filesystem_loader = new \Twig\Loader\FilesystemLoader(array($template_root, DIR_TEMPLATE));
			$loader = new \Twig\Loader\ChainLoader(array($main_loader, $filesystem_loader));

			$twig = new \Twig\Environment($loader, $config);

			return $twig->render($filename . '.twig', $this->data);
		} catch (\Exception $e) {
			trigger_error('Error: Could not load template ' . $filename . '! ' . $e->getMessage());
			exit();
		}	
	}

	private function getTemplateRoot($filename) {
		$marker = '/template/';
		$position = strpos($filename, $marker);

		if ($position === false) {
			return DIR_TEMPLATE;
		}

		$relative_root = substr($filename, 0, $position + strlen($marker));

		return rtrim(DIR_TEMPLATE, '/\\') . '/' . ltrim($relative_root, '/\\');
	}
}
