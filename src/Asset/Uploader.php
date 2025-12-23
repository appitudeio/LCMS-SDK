<?php
	/**
	 *	Uploads given file type to AWS S3
	 *	
	 *	usage: (try / catch)
	 *		Uploader::image($local_image_file);
	 *		Uploader::file($local_file);
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2020-09-12
	 *  @updated 	2023-01-09 - Supports Singleton (Methods are kept 'static' because of direct usage)
	 * 				+ can be used without credentials (iAM-role from AWS)
	 * 				2025-02-19 - Added provider support (LCMS 3.3)
	 */
	namespace LCMS\Asset;
	
	use LCMS\Core\ServiceRegistry;
	use LCMS\Asset\File;
	use LCMS\Asset\Provider;
	use LCMS\Util\Singleton;
	use GuzzleHttp\Client;

	use Exception;

	class Uploader
	{
		use Singleton;

		private array $config = [
			'max_file_size' => 20 * 1024 * 1024 // 20MB
		];
		
		/**
		 * Allowed MIME types per asset type
		 */
		private array $allowedTypes = [
			'image' => [
				"image/gif", 
				"image/jpeg", 
				"image/jpg", 
				"image/png", 
				"image/svg", 
				"image/svg+xml", 
				"image/webp", 
				"image/avif", 
				"image/heic"
			],
			'file'  => [
				'text/plain',
				'application/pdf',
				'application/msword',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/vnd.ms-excel',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/vnd.ms-powerpoint',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation'
			],
			'font'  => [
				'font/sfnt', 
				'application/x-font-ttf',
				'application/x-font-truetype',
				'application/x-font-opentype',
				'application/octet-stream', 
				'application/vnd.ms-fontobject',
				'application/vnd.ms-opentype',
				'application/font-woff',
				'application/font-woff2'
			]
		];

		/**
		 * Download a file from a URL and return a File object
		 *
		 * No MIME validation - use image()/file()/etc. when uploading if validation needed.
		 */
		protected function download(string $url, ?string $filename = null): File
		{
			// Generate filename if not provided
			if (!$filename)
			{
				$mime = $this->getMimeFromUrl($url);
				$extension = $this->getExtensionFromMime($mime);
				$filename = time() . "_" . substr(md5(rand()), 0, 10) . "." . $extension;
			}

			$tmpPath = rtrim(sys_get_temp_dir(), '/') . '/' . $filename;

			if (!copy($url, $tmpPath))
			{
				throw new Exception("Could not download file from URL");
			}

			return new File($tmpPath, $filename);
		}

		/**
		 * Common upload logic for all asset types
		 */
		protected function upload(File $file, string $path): string
		{
			$this->validate($file);

			$final_path = $this->buildPath($file, $path);

			return ServiceRegistry::get(Provider::class)::upload($file, $final_path);
		}

		/**
		 * Validate asset type and file
		 */
		protected function validate(File $file): void
		{
			if(false === ServiceRegistry::has(Provider::class))
			{
				throw new Exception("The Uploader has no Provider initialized");
			}
			elseif (!$file->isValid())
			{
				throw new Exception($file->getErrorMessage());
			}
			elseif ($file->getSize() > $this->config['max_file_size'])
			{
				throw new Exception(
					"File size exceeds maximum allowed size of " .
					($this->config['max_file_size'] / 1024 / 1024) . "MB"
				);
			}
		}

		/**
		 * Magic method to handle type-based uploads (image, file, font, etc.)
		 *
		 * Dynamically creates upload methods based on $allowedTypes keys.
		 * Called via Singleton's __callStatic routing.
		 *
		 * Usage:
		 *   Uploader::image($file, "path")                    // Uses default image types
		 *   Uploader::image($file, "path", ['image/jpeg'])    // Custom allowed types
		 *   Uploader::file($file, "path")                     // Uses default file types
		 */
		public function __call(string $name, array $arguments): mixed
		{
			// Check if this is a type-based upload (image, file, font, etc.)
			if (isset($this->allowedTypes[$name]))
			{
				$file = $arguments[0] ?? throw new Exception("File argument required for {$name}()");
				$path = $arguments[1] ?? throw new Exception("Path argument required for {$name}()");
				$customTypes = $arguments[2] ?? null;

				$allowed = $customTypes ?? $this->allowedTypes[$name];
				$this->validateMimeType($file, $allowed);

				return $this->upload($file, $path);
			}

			// Fall back to existing protected methods (upload, download, validate)
			if (method_exists($this, $name))
			{
				return $this->$name(...$arguments);
			}

			throw new Exception("Undefined class method: {$name}");
		}

		/**
		 * Validate file MIME type against allowed types
		 */
		protected function validateMimeType(File $file, array $allowedTypes): void
		{
			$mime = $file->getMimeType();
			if (!in_array($mime, $allowedTypes))
			{
				$typeNames = array_map(fn($t) => explode('/', $t)[1] ?? $t, $allowedTypes);
				throw new Exception("Invalid file type '{$mime}'. Allowed: " . implode(', ', $typeNames));
			}
		}

		/**
		 * Build the final path for the asset
		 */
		private function buildPath(File $file, string $path): string
		{
			// If the basename doesn't have a file extension, treat it as a directory
			if (!str_contains(basename($path), '.'))
			{
				$filename = $this->generateFileName($file);
				return rtrim($path, '/') . "/" . $filename;
			}

			// Path includes filename with extension, use as-is
			return $path;
		}

		/**
		 * Generate a unique filename
		 */
		private function generateFileName(File $file): string
		{
			$basename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
			return $this->slug($basename) . "-" . substr(md5(rand()), 0, 5) . "." . $file->getClientOriginalExtension();
		}

		/**
		 * Convert string to URL-friendly slug
		 */
		private function slug(string $text): string
		{
			$text = preg_replace('/[^a-z0-9]+/i', '-', strtolower($text));
			return trim($text, '-');
		}

		/**
		 * Get MIME type from URL using Guzzle
		 */
		private static function getMimeFromUrl(string $url): string
		{
			$client = new Client();
			$response = $client->head($url, [
				'allow_redirects' => true
			]);

			$contentType = $response->getHeaderLine('Content-Type');
			return trim(explode(';', $contentType)[0]);
		}

		/**
		 * Get file extension from MIME type
		 */
		private static function getExtensionFromMime(string $mime): string
		{
			// Special cases that don't match their extension
			$special_cases = [
				'image/jpeg' => 'jpg',
				'image/svg+xml' => 'svg'
			];

			if (isset($special_cases[$mime])) 
			{
				return $special_cases[$mime];
			}

			// For standard mime types, just use the last part
			$parts = explode('/', $mime);
			return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $parts[1]));
		}
	}
?>