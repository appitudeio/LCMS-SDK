<?php
    namespace LCMS\Asset;

    use LCMS\Asset\Provider;
    use LCMS\Asset\File;
    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\GuzzleException;

    class ImgEngine extends Provider 
    {
        private ?Client $client = null;
        private string $api_url = "https://api2.logicalcms.com";
        private string $asset_url = "https://asset.logicalcms.com";

        protected function init(array $config): self
        {
            $this->config = $config;

            return $this;
        }

        public function upload(File $file, string $path = "/"): string 
        {
            try 
            {
                $this->validateFile($file);

                // 1) Get the upload URL
                $response = $this->client()->post('', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['api_key']
                    ],
                    'json' => [
                        'path' => "/" . $this->normalizePath($path === "/" ? $file->getName() : $path),
                        'contentType' => $file->getMimeType()
                    ]
                ]);

                $data = json_decode($response->getBody(), true);

                if (!isset($data['uploadUrl'], $data['assetUrl'])) 
                {
                    throw new \RuntimeException('Invalid response from server');
                }

                // 2) Upload the file
                $this->client()->put($data['uploadUrl'], [
                    'body' => (string) $file,
                    'headers' => [
                        'Content-Type' => $file->getMimeType()
                    ]
                ]);

                return $data['assetUrl'];
            } 
            catch (GuzzleException $e) 
            {
                throw new \RuntimeException('Failed to upload file: ' . $e->getMessage());
            }
        }

        protected function get(string $path = ""): string 
        {
            return rtrim($this->asset_url, '/') . '/' . $this->config['domain'] . '/' . (!empty($path) ? ltrim($this->normalizePath($path), '/') : "");
        }

        public function delete(string $path): void 
        {
            try 
            {
                $this->client()->delete($this->normalizePath($path), [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['api_key']
                    ]
                ]);
            } 
            catch (GuzzleException $e) 
            {
                throw new \RuntimeException('Failed to delete file: ' . $e->getMessage());
            }
        }

        protected function validateConfig(array $config): void 
        {
            if (!isset($config['domain'], $config['api_key'])) 
            {
                throw new \InvalidArgumentException('Domain and API key are required');
            }
        }

        protected function client(): Client 
        {
            if($this->client) 
            {
                return $this->client;
            }

            $this->client = new Client([
                'base_uri' => $this->api_url . "/" . $this->config['domain'] . "/asset",
            ]);
            
            return $this->client;
        }
    }
?>