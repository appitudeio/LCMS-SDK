<?php
    namespace LCMS\Asset;

    use LCMS\Asset\File;
    use LCMS\Core\ServiceRegistry;
    use LCMS\Util\Singleton;
    
    interface MediaProvider {
        /**
         * Upload a file to the media provider
         * 
         * @param File $file The file to upload
         * @param string $path Optional path/filename
         * @return string The URL to the uploaded file
         */
        public function upload(File $file, string $path = "/"): string;

        /**
         * Get a file from the media provider
         * 
         * @param string $path Path to the file
         * @return string The URL to the file
         *  - NOTE: It's protected because it's a Singleton method, which cant be protected in an interface
         */
        //public function get(string $path): string;

        /**
         * Delete a file from the media provider
         * 
         * @param string $path Path to the file
         */
        public function delete(string $path): void;
    }

    abstract class Provider implements MediaProvider
    {
        use Singleton;

        protected array $config;
        
        protected function init(array $config): self
        {
            $this->validateConfig($config);
            $this->config = $config;

            ServiceRegistry::add(Provider::class, $this);

            return $this;
        }

        /**
         * Normalize a path by cleaning up slashes and special characters
         */
        protected function normalizePath(string $path): string 
        {
            // Remove multiple slashes
            $path = preg_replace('#/+#', '/', $path);
            
            // Remove leading/trailing slashes
            return trim($path, '/');
        }

        /**
         * Validate file before upload
         */
        protected function validateFile(File $file): void 
        {
            if (!$file->isValid()) {
                throw new \RuntimeException($file->getErrorMessage());
            }
        }

        /**
         * Get file extension from path or mime type
         */
        protected function getExtension(File $file, string $path): string 
        {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            if (!$extension) {
                $extension = $file->getClientOriginalExtension();
            }
            return $extension;
        }

        protected function getAssetDomain(): string
        {
            return $this->config['domain'];
        }

        /**
         * Validate provider configuration
         */
        abstract protected function validateConfig(array $config): void;

        public function __invoke(string $asset): string
        {
            return $this->get($asset);
        }
    }
?>