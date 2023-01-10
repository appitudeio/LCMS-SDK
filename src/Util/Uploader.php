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
	 */
	namespace LCMS\Util;
	
	use LCMS\Core\File;
	use LCMS\Util\Singleton;
	use Aws\S3\S3Client;
	use \Exception;

	class Uploader
	{
		use Singleton;

		const TMP_PATH = "/tmp";
		private $config = array();
		private $s3 = false;

		/*private static $validators 	= array(
			'max_file_size'	=> 25000, // in kb
			'max_width'		=> 3200,
			'max_height'	=> 3200
		);*/

		private $allowed_mimes = array(
			'image'	=> array("image/gif", "image/jpeg", "image/jpg", "image/png", "image/svg", "image/svg+xml", "image/webp"),
			'file'	=> array(
				// Basic
				'text/plain',
				'application/pdf',

				// Word
				'application/msword',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/vnd.ms-excel',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/vnd.ms-powerpoint',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation'
			),
			'font' => array(
				'font/sfnt', 
				'application/x-font-ttf',
				'application/x-font-truetype',
				'application/x-font-opentype',
				'application/octet-stream', 
				'application/vnd.ms-fontobject',
				'application/vnd.ms-opentype',
				'application/font-woff',
				'application/font-woff2'
			)			
		);

		public static function init(array $_config): self
		{
			if(!isset($_config['credentials']) && !isset($_config['access_key'], $_config['access_secret']))
			{
				throw new Exception("Uploader can't be initialized w/o credentials | key+secret");
			}

			self::getInstance()->config = array(
				'region'					=> $_config['region'],
				'bucket'					=> $_config['bucket'],
				'bucket_images_root_path'	=> $_config['bucket_images_root_path'] ?? "",
				'credentials'				=> $_config['credentials'] ?? array(
					'key'				=> $_config['access_key'] ?? null,
					'secret'			=> $_config['access_secret'] ?? null
				)
			);

			self::getS3(); // Init the Client
			
			return self::getInstance();
		}

		/**
		 *
		 */
		public static function image(File $local_file, string $to_path = "images", string | null $new_filename = null)
		{
			self::getInstance()->validate("image", $local_file);

			return self::getInstance()->uploadToS3($local_file, $to_path, $new_filename);
		}

		/** 
		 *
		 */
		public static function file(File $local_file, string $to_path = "files", string | null $new_filename = null)
		{
			self::getInstance()->validate("file", $local_file);

			return self::getInstance()->uploadToS3($local_file, $to_path, $new_filename);
		}

		public static function font(File $local_file, string $to_path = "fonts", string | null $new_filename = null)
		{
			self::getInstance()->validate("font", $local_file);

			return self::getInstance()->uploadToS3($local_file, $to_path, $new_filename);
		}		

		/**
		 *	Type is either file || image
		 */
		private static function validate(string $type, File $local_file): bool
		{
			if(!self::getInstance()->s3)
			{
				throw new Exception("Uploader needs to be ::init(ialized) first");
			}
			elseif(!$local_file->isValid())
			{
				throw new Exception($local_file->getErrorMessage());
			}
			elseif(!in_array($local_file->getMimeType(), self::getInstance()->allowed_mimes[$type]))
			{
				throw new Exception("Mime type " . $local_file->getMimeType() . " is not allowed to be uploaded to the server as ".$type." (File: " . $local_file->getClientOriginalName().")");
			}

			return true;
		}
		
		/**
		 *	Generate a new file name, which will be unique
		 */
		public static function generateFileName(File | string $file = null, string | null $prepend_filename = null): string
		{
			$filename = time();

			if(!empty($file) && $file instanceof File)
			{
				$filename = explode(".", $file->getClientOriginalName())[0];
				$extension = $file->getClientOriginalExtension();
			}
			elseif(!empty($file))
			{
				$filename = explode(".", $file)[0];
				$extension = pathinfo($filename)['extension'];
			}

			/* Give the filename a unique name */
			$new_filename = slug($filename) . "-" . substr(md5(rand()), 0, 5) . ((isset($extension)) ? "." . $extension : "");
			$new_filename = (!empty($prepend_filename)) ? $prepend_filename . $new_filename : $new_filename;

			return $new_filename;
		}

		/* Upload image to S3 Bucket */
		public static function uploadToS3(File $local_file, string $s3_path = null, string | null $new_filename = null): string
		{
			if(!self::getInstance()->s3)
			{
				throw new Exception("Uploader not initialized");
			}

			$new_filename = (empty($new_filename)) ? self::getInstance()->generateFileName($local_file) : $new_filename;

			$s3_root_path = (!empty($s3_path)) ? self::getInstance()->config['bucket_images_root_path'] . "/". $s3_path : self::getInstance()->config['bucket_images_root_path'];
			$s3_root_path .= ($s3_root_path[strlen($s3_root_path) - 1] == "/") ? "" : "/"; // Append slash
			$s3_root_path = ltrim($s3_root_path, "/"); // Remove first slash

			// Is this an SVG image?
			$mime = $local_file->getMimeType();

			if(strpos($mime, "svg") !== false)
			{
				$mime .= "+xml";
			}

			self::getInstance()->getS3()->putObject(array(
				'Bucket' 		=> self::getInstance()->config['bucket'], 
				'Key' 			=> $s3_root_path . $new_filename, 
				'SourceFile' 	=> (string) $local_file, 
				'ContentType' 	=> $mime
			));

			return $new_filename;
		}

		/* Rewritten DownloadMethod */
	    public static function downloadImage(string $download_url, string | null $prepend_filename = null): string
	    {
	    	$mime = self::getInstance()->getMimeFromUrl($download_url);

			if(empty($mime) || !in_array($mime, self::getInstance()->allowed_mimes['image']))
			{
				throw new Exception("Mime '" . $mime . "' is not allowed to be uploaded to the server. (File: " . $download_url.")");
			}

			/*if($image_data[0] < self::$sizes['min_width'] || $image_data[0] > self::$sizes['max_width'] || $image_data[1] < self::$sizes['min_height'] || $image_data[1] > self::$sizes['max_height'])
			{
				throw new Exception("The image is too big, max allowed size: " . self::$sizes['max_width'] . "x" . self::$sizes['min_height'] . " (is: " . $image_data[0] . "x" . $image_data[1].")");
			}*/

	    	/* Since this is a remote file, let's put an extension */
	    	$extension = "png";

	    	if($mime == "image/webp")
	    	{
	    		$extension = "webp";
	    	}
			elseif($mime == "image/gif")
			{
				$extension = "gif";
			}
			elseif($mime == "image/svt")
			{
				$extension = "svg";
			}
			elseif(in_array($mime, ["image/jpeg", "image/jpg"]))
			{
				$extension = "jpg";
			}

			/* Give the filename a unique name */
			$new_filename = time() . "_" . substr(md5(rand()), 0, 10) . "." . $extension;

			$new_filename = (!empty($prepend_filename)) ? $prepend_filename . $new_filename : $new_filename;

			if (!copy($download_url, self::TMP_PATH . "/" . $new_filename))
			{
				throw new Exception("Could not download image to tmp path");
			}

	    	return $new_filename;
	    }

	    private static function getMimeFromUrl(string $_url): string
	    {
			$ch = curl_init($_url);
			curl_setopt_array($ch, array(
				CURLOPT_NOBODY => 1,
				CURLOPT_HEADER => 1,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_RETURNTRANSFER => true
			));
			curl_exec($ch);

			return trim(explode(";", curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]);   	
	    }

	    public static function getFileEndingFromMime(string $_mime): string
	    {
	    	return explode("+", explode("/", $_mime)[1])[0]; // "+" is for SVG
	    }

	    public static function setBucket(string $_bucket): void
	    {
	    	self::getInstance()->config['bucket'] = $_bucket;
	    }

		public static function getS3(): S3Client
		{
			if(!self::getInstance()->s3)
			{
				self::getInstance()->s3 = new S3Client(array(
					'version'		=> "latest",
					'region' 		=> self::getInstance()->config['region'],
					'credentials' 	=> self::getInstance()->config['credentials']
				));
			}

			return self::getInstance()->s3;
		}		
	}
?>