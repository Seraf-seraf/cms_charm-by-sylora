<?php
namespace Template;

class CspNonceTwigLoader implements \Twig\Loader\LoaderInterface {
	private $nonce_cache_key_suffix = '#csp-nonce';
	private $loader;

	public function __construct(\Twig\Loader\LoaderInterface $loader) {
		$this->loader = $loader;
	}

	public function getCacheKey(string $name): string {
		return $this->loader->getCacheKey($name) . $this->nonce_cache_key_suffix;
	}

	public function getSourceContext(string $name): \Twig\Source {
		$source = $this->loader->getSourceContext($name);
		$code = $source->getCode();

		if ($code && strpos($code, '<script') !== false) {
			$code = preg_replace('/<script\\b(?![^>]*\\bnonce=)/i', '<script nonce="{{ csp_nonce }}"', $code);
		}

		return new \Twig\Source($code, $source->getName(), $source->getPath());
	}

	public function isFresh(string $name, int $time): bool {
		return $this->loader->isFresh($name, $time);
	}

	public function exists(string $name): bool {
		return $this->loader->exists($name);
	}
}

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
			$loader = new CspNonceTwigLoader(new \Twig\Loader\ChainLoader(array($main_loader, $filesystem_loader)));

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
