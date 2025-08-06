<?php
	/**
	 * A file uploaded through a form.
	 *
	 * @author Bernhard Schussek <bschussek@gmail.com>
	 * @author Florian Eckerstorfer <florian@eckerstorfer.org>
	 * @author Fabien Potencier <fabien@symfony.com>
	*/
	namespace LCMS\Asset;

	use SplFileInfo;

	class FileException extends \Exception {}

	class File extends SplFileInfo
	{
	    private bool $test;
	    private string $originalName;
	    private string $mimeType = 'application/octet-stream';
	    private ?int $error;
	    private int $size = 0;

	    /**
	     * Accepts the information of the uploaded file as provided by the PHP global $_FILES.
	     *
	     * The file object is only created when the uploaded file is valid (i.e. when the
	     * isValid() method returns true). Otherwise the only methods that could be called
	     * on an UploadedFile instance are:
	     *
	     *   * getClientOriginalName,
	     *   * getClientMimeType,
	     *   * isValid,
	     *   * getError.
	     *
	     * Calling any other method on an non-valid instance will cause an unpredictable result.
	     *
	     * @param string  $path         The full temporary path to the file
	     * @param string  $originalName The original file name
	     * @param integer $error        The error constant of the upload (one of PHP's UPLOAD_ERR_XXX constants)
	     * @param bool    $test         Whether the test mode is active
	     *
	     * @throws FileException         If file_uploads is disabled or file not found
	     *
	     * @api
	     */
	    public function __construct(string $path, string $originalName, ?int $error = null, bool $test = false)
	    {
	        $this->originalName = $this->getName($originalName);
	        $this->error = $error ?? UPLOAD_ERR_OK;
	        $this->test = $test;

	        if ($this->error === UPLOAD_ERR_OK) 
			{
	            if (!is_file($path)) 
				{
	                throw new FileException(sprintf('File "%s" not found', $path));
	            }

	            try 
				{
	                $finfo = new \finfo(FILEINFO_MIME_TYPE);
	                $this->mimeType = $finfo->file($path);
	                $this->size = filesize($path);

	                parent::__construct($path);
	            } 
				catch (\Exception $e) 
				{
	                throw new FileException('Failed to initialize file: ' . $e->getMessage());
	            }
	        }
	    }

	    /**
	     * Returns the original file name.
	     *
	     * It is extracted from the request from which the file has been uploaded.
	     * Then it should not be considered as a safe value.
	     *
	     * @return string The original name
	     */
	    public function getClientOriginalName(): string
	    {
	        return $this->originalName;
	    }

	    /**
	     * Returns the original file extension.
	     *
	     * It is extracted from the original file name that was uploaded.
	     * Then it should not be considered as a safe value.
	     *
	     * @return string The extension
	     */
	    public function getClientOriginalExtension(): string
	    {
	        return pathinfo($this->originalName, \PATHINFO_EXTENSION);
	    }

	    /**
	     * Returns the file mime type.
	     *
	     * The client mime type is extracted from the request from which the file
	     * was uploaded, so it should not be considered as a safe value.
	     *
	     * For a trusted mime type, use getMimeType() instead (which guesses the mime
	     * type based on the file content).
	     *
	     * @return string The mime type
	     *
	     * @see getMimeType()
	     */
	    public function getClientMimeType(): string
	    {
	        return $this->mimeType;
	    }

		public function getMimeType(): string
		{
			return $this->mimeType;
		}		

	    /**
	     * Returns the extension based on the client mime type.
	     *
	     * If the mime type is unknown, returns null.
	     *
	     * This method uses the mime type as guessed by getClientMimeType()
	     * to guess the file extension. As such, the extension returned
	     * by this method cannot be trusted.
	     *
	     * For a trusted extension, use guessExtension() instead (which guesses
	     * the extension based on the guessed mime type for the file).
	     *
	     * @return string|null The guessed extension or null if it cannot be guessed
	     *
	     * @see guessExtension()
	     * @see getClientMimeType()
	     */
	    public function guessClientExtension(): ?string
	    {
	        return MimeType::getExtension($this->getClientMimeType());
	    }

	    /**
	     * Returns the upload error.
	     *
	     * If the upload was successful, the constant UPLOAD_ERR_OK is returned.
	     * Otherwise one of the other UPLOAD_ERR_XXX constants is returned.
	     *
	     * @return int The upload error
	     */
	    public function getError(): int
	    {
	        return $this->error;
	    }

	    /**
	     * Returns whether the file was uploaded successfully.
	     *
	     * @return bool True if the file has been uploaded with HTTP and no error occurred
	     */
	    public function isValid(): bool
	    {
	        $isOk = \UPLOAD_ERR_OK === $this->error;

	        return $this->test ? $isOk : $isOk && is_uploaded_file($this->getPathname());
	    }

	    /**
	     * Moves the file to a new location.
	     *
	     * @return File A File object representing the new file
	     *
	     * @throws FileException if, for any reason, the file could not have been moved
	     */
	    public function move(string $directory, ?string $name = null): bool
	    {
	        if ($this->isValid())
	        {
	            if ($this->test)
	            {
	                return false; //parent::move($directory, $name);
	            }

	            $target = $this->getTargetFile($directory, $name);

	            set_error_handler(function ($type, $msg) use (&$error) { $error = $msg; });
	            $moved = move_uploaded_file($this->getPathname(), $target);
	            restore_error_handler();
	            if (!$moved) {
	                throw new FileException(sprintf('Could not move the file "%s" to "%s" (%s).', $this->getPathname(), $target, strip_tags($error)));
	            }

	            @chmod($target, 0666 & ~umask());

	            return $target;
	        }

	        switch ($this->error) {
	            case \UPLOAD_ERR_INI_SIZE:
	                throw new IniSizeFileException($this->getErrorMessage());
	            case \UPLOAD_ERR_FORM_SIZE:
	                throw new FormSizeFileException($this->getErrorMessage());
	            case \UPLOAD_ERR_PARTIAL:
	                throw new PartialFileException($this->getErrorMessage());
	            case \UPLOAD_ERR_NO_FILE:
	                throw new NoFileException($this->getErrorMessage());
	            case \UPLOAD_ERR_CANT_WRITE:
	                throw new CannotWriteFileException($this->getErrorMessage());
	            case \UPLOAD_ERR_NO_TMP_DIR:
	                throw new NoTmpDirFileException($this->getErrorMessage());
	            case \UPLOAD_ERR_EXTENSION:
	                throw new ExtensionFileException($this->getErrorMessage());
	        }

	        throw new FileException($this->getErrorMessage());
	    }

	    /**
	     * Returns the maximum size of an uploaded file as configured in php.ini.
	     *
	     * @return int The maximum size of an uploaded file in bytes
	     */
	    public static function getMaxFilesize(): int
	    {
	        $sizePostMax = self::parseFilesize(ini_get('post_max_size'));
	        $sizeUploadMax = self::parseFilesize(ini_get('upload_max_filesize'));

	        return min($sizePostMax ?: \PHP_INT_MAX, $sizeUploadMax ?: \PHP_INT_MAX);
	    }

	    /**
	     * Returns the given size from an ini value in bytes.
	     */
	    private static function parseFilesize($size): int
	    {
	        if ('' === $size) 
			{
	            return 0;
	        }

	        $size = strtolower($size);

	        $max = ltrim($size, '+');
	        if (0 === strpos($max, '0x')) 
			{
	            $max = \intval($max, 16);
	        } 
			elseif (0 === strpos($max, '0')) 
			{
	            $max = \intval($max, 8);
	        } 
			else 
			{
	            $max = (int) $max;
	        }

	        switch (substr($size, -1)) 
			{
	            case 't': $max *= 1024;
	            // no break
	            case 'g': $max *= 1024;
	            // no break
	            case 'm': $max *= 1024;
	            // no break
	            case 'k': $max *= 1024;
	        }

	        return $max;
	    }

	    /**
	     * Returns an informative upload error message.
	     *
	     * @return string The error message regarding the specified error code
	     */
	    public function getErrorMessage(): string
	    {
	        static $errors = [
	            \UPLOAD_ERR_INI_SIZE => 'The file "%s" exceeds your upload_max_filesize ini directive (limit is %d KiB).',
	            \UPLOAD_ERR_FORM_SIZE => 'The file "%s" exceeds the upload limit defined in your form.',
	            \UPLOAD_ERR_PARTIAL => 'The file "%s" was only partially uploaded.',
	            \UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
	            \UPLOAD_ERR_CANT_WRITE => 'The file "%s" could not be written on disk.',
	            \UPLOAD_ERR_NO_TMP_DIR => 'File could not be uploaded: missing temporary directory.',
	            \UPLOAD_ERR_EXTENSION => 'File upload was stopped by a PHP extension.',
	        ];

	        $errorCode = $this->error;
	        $maxFilesize = \UPLOAD_ERR_INI_SIZE === $errorCode ? self::getMaxFilesize() / 1024 : 0;
	        $message = isset($errors[$errorCode]) ? $errors[$errorCode] : 'The file "%s" was not uploaded due to an unknown error.';

	        return sprintf($message, $this->getClientOriginalName(), $maxFilesize);
	    }

	    /**
	     * Returns locale independent base name of the given path.
	     *
	     * @return string
	     */
	    protected function getName(string $name): string
	    {
	        $originalName = str_replace('\\', '/', $name);
	        $pos = strrpos($originalName, '/');
	        $originalName = false === $pos ? $originalName : substr($originalName, $pos + 1);

	        return $originalName;
	    }

	    /**
	     * Get the file contents when the object is cast to string
	     */
	    public function __toString(): string
	    {
	        if (!$this->isValid()) 
			{
	            throw new FileException('Cannot get content of invalid file');
	        }

	        $content = file_get_contents($this->getPathname());
	        if ($content === false) 
			{
	            throw new FileException('Failed to read file contents');
	        }

	        return $content;
	    }

	    /**
	     * Returns a new instance with the specified new path and name
	     */
	    private function getTargetFile(string $directory, ?string $name = null): string
	    {
	        if (!is_dir($directory)) 
			{
	            if (false === @mkdir($directory, 0777, true) && !is_dir($directory)) 
				{
	                throw new FileException(sprintf('Unable to create the "%s" directory', $directory));
	            }
	        } 
			elseif (!is_writable($directory)) 
			{
	            throw new FileException(sprintf('Unable to write in the "%s" directory', $directory));
	        }

	        $target = rtrim($directory, '/\\').\DIRECTORY_SEPARATOR.($name === null ? $this->getClientOriginalName() : $name);
	        
	        return $target;
	    }
	}

	class MimeType
	{
		/*
		* Array of valid MIME types
		* See: https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Common_types
		* Also see: https://www.iana.org/assignments/media-types/media-types.xhtml
		*/
		private static array $mime_types = [
			'aac' => 'audio/aac',
			'abw' => 'application/x-abiword',
			'arc' => 'application/x-freearc',
			'avif' => 'image/avif',
			'avi' => 'video/x-msvideo',
			'azw' => 'application/vnd.amazon.ebook',
			'bin' => 'application/octet-stream',
			'bmp' => 'image/bmp',
			'bz' => 'application/x-bzip',
			'bz2' => 'application/x-bzip2',
			'cda' => 'application/x-cdf',
			'csh' => 'application/x-csh',
			'css' => 'text/css',
			'csv' => 'text/csv',
			'doc' => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'eot' => 'application/vnd.ms-fontobject',
			'epub' => 'application/epub+zip',
			'gz' => 'application/gzip',
			'gif' => 'image/gif',
			'htm' => 'text/html',
			'html' => 'text/html',
			'ico' => 'image/vnd.microsoft.icon',
			'ics' => 'text/calendar',
			'jar' => 'application/java-archive',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'js' => 'text/javascript',
			'json' => 'application/json',
			'jsonld' => 'application/ld+json',
			'mid' => 'audio/midi audio/x-midi',
			'midi' => 'audio/midi audio/x-midi',
			'mjs' => 'text/javascript',
			'mp3' => 'audio/mpeg',
			'mp4' => 'video/mp4',
			'mpeg' => 'video/mpeg',
			'mpkg' => 'application/vnd.apple.installer+xml',
			'odp' => 'application/vnd.oasis.opendocument.presentation',
			'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
			'odt' => 'application/vnd.oasis.opendocument.text',
			'oga' => 'audio/ogg',
			'ogv' => 'video/ogg',
			'ogx' => 'application/ogg',
			'opus' => 'audio/opus',
			'otf' => 'font/otf',
			'png' => 'image/png',
			'pdf' => 'application/pdf',
			'php' => 'application/x-httpd-php',
			'ppt' => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'rar' => 'application/vnd.rar',
			'rtf' => 'application/rtf',
			'sh' => 'application/x-sh',
			'svg' => 'image/svg+xml',
			'swf' => 'application/x-shockwave-flash',
			'tar' => 'application/x-tar',
			'tif' => 'image/tiff',
			'tiff' => 'image/tiff',
			'ts' => 'video/mp2t',
			'ttf' => 'font/ttf',
			'txt' => 'text/plain',
			'vsd' => 'application/vnd.visio',
			'wav' => 'audio/wav',
			'weba' => 'audio/webm',
			'webm' => 'video/webm',
			'webp' => 'image/webp',
			'woff' => 'font/woff',
			'woff2' => 'font/woff2',
			'xhtml' => 'application/xhtml+xml',
			'xls' => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'xml' => 'text/xml',
			'xul' => 'application/vnd.mozilla.xul+xml',
			'zip' => 'application/zip',
			'3gp' => 'video/3gpp',
			'3g2' => 'video/3gpp2',
			'7z' => 'application/x-7z-compressed'
		];

		/**
		 * Return array of all MIME types.
		 *
		 * @return array
		 */
		public static function getMimeTypes(): array
		{
			return self::$mime_types;
		}

		/**
		 * Adds new MIME type definitions.
		 *
		 * @param array $types (Array whose keys are the file extension and values are the MIME type string)
		 *
		 * @return void
		 */
		public static function addMimeType(array $types): void
		{
			self::$mime_types = array_merge(self::getMimeTypes(), $types);
		}

		/**
		 * Return extension of a given file, or empty string if not existing.
		 *
		 * @param string $file
		 *
		 * @return string|null
		 */
		public static function getExtension(string $file): ?string
		{
			$extension = explode('.', strrev($file), 2);

			if (isset($extension[1])) // If a period exists in the filename
			{ 
				return strtolower(strrev($extension[0]));
			}

			return null;
		}

		/**
		 * Checks if a file has a given extension.
		 *
		 * @param string $extension
		 * @param string $file
		 *
		 * @return bool
		 */
		public static function hasExtension(string $extension, string $file): bool
		{
			return self::getExtension($file) == $extension;
		}

		/**
		 * Get MIME type from file extension.
		 *
		 * @param string $extension
		 * @param string $default (Default MIME type to return if none found for given extension)
		 *
		 * @return string
		 */
		public static function fromExtension(string $extension, string $default = 'application/octet-stream'): string
		{
			if (array_key_exists($extension, self::$mime_types)) 
			{

				return self::$mime_types[$extension];

			}

			return $default;
		}

		/**
		 * Get MIME type from file name.
		 *
		 * @param string $file
		 * @param string $default (Default MIME type to return if none found for given extension)
		 *
		 * @return string
		 */
		public static function fromFile(string $file, string $default = 'application/octet-stream'): string
		{
			$extension = self::getExtension($file);

			if (array_key_exists($extension, self::$mime_types)) 
			{
				return self::$mime_types[$extension];
			}

			return $default;
		}
	}
?>