<?php
class ModelToolImage extends Model {
	public function resize($filename, $width, $height) {
		if (!is_file(DIR_IMAGE . $filename) || substr(str_replace('\\', '/', realpath(DIR_IMAGE . $filename)), 0, strlen(DIR_IMAGE)) != str_replace('\\', '/', DIR_IMAGE)) {
			return;
		}

		$extension = pathinfo($filename, PATHINFO_EXTENSION);

		$image_old = $filename;
		$image_new = 'cache/' . utf8_substr($filename, 0, utf8_strrpos($filename, '.')) . '-' . (int)$width . 'x' . (int)$height . '.' . $extension;

		if (!is_file(DIR_IMAGE . $image_new) || (filemtime(DIR_IMAGE . $image_old) > filemtime(DIR_IMAGE . $image_new))) {
			list($width_orig, $height_orig, $image_type) = getimagesize(DIR_IMAGE . $image_old);
				 
			if (!in_array($image_type, array(IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP))) { 
				if ($this->request->server['HTTPS']) {
					return $this->config->get('config_ssl') . 'image/' . $image_old;
 				} else {
					return $this->config->get('config_url') . 'image/' . $image_old;
				}
			}
						
			$path = '';

			$directories = explode('/', dirname($image_new));

			foreach ($directories as $directory) {
				$path = $path . '/' . $directory;

				if (!is_dir(DIR_IMAGE . $path)) {
					@mkdir(DIR_IMAGE . $path, 0777);
				}
			}

			if ($width_orig != $width || $height_orig != $height) {
				$image = new Image(DIR_IMAGE . $image_old);
				$image->resize($width, $height);
				$image->save(DIR_IMAGE . $image_new);
			} else {
				copy(DIR_IMAGE . $image_old, DIR_IMAGE . $image_new);
			}
		}
		
		$image_new = str_replace(' ', '%20', $image_new);  // fix bug when attach image on email (gmail.com). it is automatic changing space " " to +
		
		if ($this->request->server['HTTPS']) {
			return $this->config->get('config_ssl') . 'image/' . $image_new;
		} else {
			return $this->config->get('config_url') . 'image/' . $image_new;
		}
	}

	public function resizeWithSources($filename, int $width, int $height): array {
		$fallback = $this->resize($filename, $width, $height);

		if (!$fallback) {
			return array(
				'src'     => '',
				'sources' => array(),
				'width'   => $width,
				'height'  => $height
			);
		}

		$sources = array();
		$extension = pathinfo($filename, PATHINFO_EXTENSION);
		$image_new = 'cache/' . utf8_substr($filename, 0, utf8_strrpos($filename, '.')) . '-' . $width . 'x' . $height . '.' . $extension;
		$source_path = DIR_IMAGE . $image_new;

		if (is_file($source_path)) {
			$webp = $this->createOptimizedSource($source_path, $image_new, 'webp');

			if ($webp) {
				$sources[] = array(
					'type' => 'image/webp',
					'src'  => $webp
				);
			}

			$avif = $this->createOptimizedSource($source_path, $image_new, 'avif');

			if ($avif) {
				array_unshift($sources, array(
					'type' => 'image/avif',
					'src'  => $avif
				));
			}
		}

		return array(
			'src'     => $fallback,
			'sources' => $sources,
			'width'   => $width,
			'height'  => $height
		);
	}

	private function createOptimizedSource(string $source_path, string $relative_path, string $format): string {
		if ($format == 'webp' && !function_exists('imagewebp')) {
			return '';
		}

		if ($format == 'avif' && !function_exists('imageavif')) {
			return '';
		}

		$target_relative_path = preg_replace('/\.[^.]+$/', '.' . $format, $relative_path);

		if (!is_string($target_relative_path)) {
			return '';
		}

		$target_path = DIR_IMAGE . $target_relative_path;

		if (is_file($target_path) && filemtime($target_path) >= filemtime($source_path)) {
			return $this->getImageUrl($target_relative_path);
		}

		$image_info = getimagesize($source_path);

		if (!is_array($image_info) || !isset($image_info[2])) {
			return '';
		}

		if ($image_info[2] == IMAGETYPE_PNG) {
			$image = imagecreatefrompng($source_path);
		} elseif ($image_info[2] == IMAGETYPE_JPEG) {
			$image = imagecreatefromjpeg($source_path);
		} elseif ($image_info[2] == IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
			$image = imagecreatefromwebp($source_path);
		} else {
			return '';
		}

		if (!is_object($image) && !is_resource($image)) {
			return '';
		}

		imagepalettetotruecolor($image);
		imagealphablending($image, true);
		imagesavealpha($image, true);

		if ($format == 'webp') {
			$created = imagewebp($image, $target_path, 82);
		} else {
			$created = imageavif($image, $target_path, 52);
		}

		imagedestroy($image);

		return $created ? $this->getImageUrl($target_relative_path) : '';
	}

	private function getImageUrl(string $image): string {
		$image = str_replace(' ', '%20', $image);

		if ($this->request->server['HTTPS']) {
			return $this->config->get('config_ssl') . 'image/' . $image;
		}

		return $this->config->get('config_url') . 'image/' . $image;
	}
}
