<?php
    namespace LCMS\Asset;

    use Exception;
    use LCMS\Asset\Provider;
    use LCMS\Asset\File;
    use LCMS\Storage;

    class LcmsStorage extends Provider
    {
        private ?Storage $client = null;

        protected function upload(File $file, string $path = "/"): string
        {
            $this->validateFile($file);

            $normalizedPath = '/' . $this->normalizePath($path === "/" ? $file->getClientOriginalName() : $path);

            try
            {
                $response = $this->client()->upload(
                    $file->getPathname(),
                    $normalizedPath
                );

                return $response->url['asset'];
            }
            catch (Exception $e)
            {
                throw new Exception('Failed to upload file: ' . $e->getMessage());
            }
        }

        protected function delete(string $path): void
        {
            try
            {
                $this->client()->delete($path);
            }
            catch (Exception $e)
            {
                throw new Exception('Failed to delete file: ' . $e->getMessage());
            }
        }

        protected function list(string $path = ""): array
        {
            try
            {
                $response = $this->client()->list($path);

                // Extract URLs from items
                return array_map(fn($item) => $item['url'], $response->items);
            }
            catch (Exception $e)
            {
                throw new Exception('Failed to list directory: ' . $e->getMessage());
            }
        }

        protected function validateConfig(array $config): void
        {
            if (!isset($config['domain'], $config['api_key']))
            {
                throw new Exception('Domain and API key are required');
            }
        }

        private function client(): Storage
        {
            if ($this->client)
            {
                return $this->client;
            }

            $this->client = new Storage(
                $this->config['domain'],
                $this->config['api_key']
            );

            return $this->client;
        }
    }
?>