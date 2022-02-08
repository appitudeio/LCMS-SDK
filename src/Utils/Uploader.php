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
	 */
	namespace LCMS\Utils;
	
	use \Exception;
	use Aws\S3\S3Client;

	class Uploader
	{
		const TMP_PATH 				= "/tmp";
		public static $initialized = false;
		private static $config 		= array();

		private static $validators 	= array(
			'max_file_size'	=> 25000, // in kb
			'max_width'		=> 3200,
			'max_height'	=> 3200
		);

		private static $allowed_mimes = array(
			'image'	=> array("image/gif", "image/jpeg", "image/jpg", "image/png", "image/svg", "image/webp"),
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
			)
		);

		public static function init($config)
		{
			self::$config = array(
				'region'					=> $config['region'],
				'bucket'					=> $config['bucket'],
				'bucket_images_root_path'	=> $config['bucket_images_root_path'] ?? "",
				'access_key'				=> $config['access_key'],
				'access_secret'				=> $config['access_secret'],
			);

			self::$initialized = true;
		}

		/**
		 *
		 */
		public static function image($local_file, $to_path = null, $new_filename = null)
		{
			/*$to = (empty($to_path)) ? "images" : $to_path;
			$to = ($to[0] == "/") ? substr($to, 1) : $to;
			$to = ($to[strlen($to) - 1] == "/") ? substr($to, 0, -1) : $to;*/

			self::validate("image", $local_file['tmp_name']);

			return self::uploadToS3($local_file, $to_path, $new_filename);
		}

		/** 
		 *
		 */
		public static function file($local_file, $to_path = null, $new_filename = null)
		{
			$to = (empty($to_path)) ? "files" : $to_path;
			$to = ($to[0] == "/") ? substr($to, 1) : $to;
			$to = ($to[strlen($to) - 1] == "/") ? substr($to, 0, -1) : $to;

			self::validate("file", $local_file['tmp_name']);

			return self::uploadToS3($local_file, $to, $new_filename);
		}

		/**
		 *	Type is either file || image
		 */
		private static function validate($type, $local_file)
		{
			if(!self::$initialized)
			{
				throw new Exception("Uploader needs to be initialized first");
			}

			// Does it exist?
			if(!is_file($local_file))
			{
				throw new Exception("The local file &quot;" . $local_file . "&quot; doesnt exist");
			}

			// Validate mimes
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$file_mime = finfo_file($finfo, $local_file);
			finfo_close($finfo);

			if(!in_array($file_mime, self::$allowed_mimes[$type]))
			{
				throw new Exception("Mime &quot;" . $file_mime . "&quot; is not allowed to be uploaded to the server. (File: " . $local_file.")");
			}

			// Validate size
			$file_size = filesize($local_file) / 1024; // to kb

			if($file_size > self::$validators['max_file_size'])
			{
				throw new Exception("The file is too big (".($file_size / 1024)." mb), max allowed file size: " .round(self::$validators['max_file_size'] / 1024) . "mb");
			}

			// If uploading a image, validate sizes
			/*if($type == "image")
			{
				$image_data = getimagesize($local_file);

				if( $image_data[0] > self::$validators['max_width'] || $image_data[1] < self::$validators['min_height'] || $image_data[1] > self::$validators['max_height'])
				{
					throw new Exception("The image is too big, max allowed size: " . self::$validators['max_width'] . "x" . self::$validators['min_height'] . " (is: " . $image_data[0] . "x" . $image_data[1].")");
				}
			}*/

			return true;
		}
		
		/**
		 *	Generate a new file name, which will be unique
		 */
		public static function generateFileName($file = null, $prepend_filename = null)
		{
			/**
			 * Incase no destination_name were specified, we generate one.
			 */
			$file = (!empty($file) && is_array($file) && isset($file['name'])) ? $file['name'] : ((!empty($file)) ? $file : null);

			if(!empty($file))
			{
				$path_info = pathinfo($file);
				
				$extension = $path_info['extension'];
			}

			/* Give the filename a unique name */
			$new_filename = time() . "_" . substr(md5(rand()), 0, 10);

			if(isset($extension))
			{
				$new_filename .= "." . $extension;
			}

			$new_filename = str_replace(" ", "_", $new_filename);

			$new_filename = (!empty($prepend_filename)) ? $prepend_filename . $new_filename : $new_filename;

			return $new_filename;
		}

		/* Upload image to S3 Bucket */
		public static function uploadToS3($local_file, $s3_path = null, $new_filename = null)
		{
			if(!self::$initialized)
			{
				throw new Exception("Uploader not initialized");
			}

			$new_filename = (empty($new_filename)) ? self::generateFileName($local_file) : $new_filename;

			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mime = finfo_file($finfo, $local_file['tmp_name']);

			$s3_root_path = (!empty($s3_path)) ? self::$config['bucket_images_root_path'] . "/". $s3_path : self::$config['bucket_images_root_path'];
			$s3_root_path .= ($s3_root_path[strlen($s3_root_path) - 1] == "/") ? "" : "/"; // Append slash

			// Is this an SVG image?
			if(strpos($mime, "svg") !== false)
			{
				$mime .= "+xml";
			}

			$s3 = S3Client::factory(array(
			    'version'	=> "latest",
			    'region' 	=> self::$config['region'],
			    'credentials'	=> array(
					'key'		=> self::$config['access_key'],
					'secret'	=> self::$config['access_secret']
			    )
			));
			
			$s3->putObject(array(
				'Bucket' 		=> self::$config['bucket'], 
				'Key' 			=> $s3_root_path . $new_filename, 
				'SourceFile' 	=> $local_file['tmp_name'], 
				'ACL' 			=> "public-read", 
				'ContentType' 	=> $mime
			));

			return $new_filename;
		}

		/* Rewritten DownloadMethod */
	    public static function downloadImage($download_url, $prepend_filename = null)
	    {
	    	$mime = self::getMimeFromUrl($download_url);

			if(empty($mime) || !in_array($mime, self::$allowed_mimes['image']))
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

	    private static function getMimeFromUrl($_url)
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

	    public static function getFileEndingFromMime($mime)
	    {
	    	return explode("+", explode("/", $mime)[1])[0]; // "+" is for SVG
	    }

	    public static function setBucket($_bucket)
	    {
	    	self::$config['bucket'] = $_bucket;
	    }
	}
?>