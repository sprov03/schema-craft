<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * Generates the SdkConnector class — the HTTP transport layer for the SDK.
 *
 * Wraps Guzzle with bearer token authentication and JSON request/response handling.
 */
class SdkConnectorGenerator
{
    /**
     * Generate the SdkConnector class PHP code.
     */
    public function generate(string $namespace): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use GuzzleHttp\\Client;
        use GuzzleHttp\\ClientInterface;

        class SdkConnector
        {
            /** @var string */
            private \$baseUrl;

            /** @var string */
            private \$token;

            /** @var ClientInterface */
            private \$httpClient;

            /**
             * @param string \$baseUrl
             * @param string \$token
             * @param ClientInterface|null \$httpClient
             */
            public function __construct(\$baseUrl, \$token, \$httpClient = null)
            {
                \$this->baseUrl = \$baseUrl;
                \$this->token = \$token;
                \$this->httpClient = \$httpClient ?: new Client();
            }

            /**
             * @param string \$path
             * @return array
             */
            public function get(\$path)
            {
                \$response = \$this->httpClient->request('GET', \$this->url(\$path), [
                    'headers' => \$this->headers(),
                    'http_errors' => false,
                ]);

                return \$this->handleResponse(\$response);
            }

            /**
             * @param string \$path
             * @param array \$data
             * @return array
             */
            public function post(\$path, array \$data)
            {
                \$response = \$this->httpClient->request('POST', \$this->url(\$path), [
                    'headers' => \$this->headers(),
                    'json' => \$data,
                    'http_errors' => false,
                ]);

                return \$this->handleResponse(\$response);
            }

            /**
             * @param string \$path
             * @param array \$data
             * @return array
             */
            public function put(\$path, array \$data)
            {
                \$response = \$this->httpClient->request('PUT', \$this->url(\$path), [
                    'headers' => \$this->headers(),
                    'json' => \$data,
                    'http_errors' => false,
                ]);

                return \$this->handleResponse(\$response);
            }

            /**
             * @param string \$path
             * @param array \$data
             * @return array
             */
            public function patch(\$path, array \$data)
            {
                \$response = \$this->httpClient->request('PATCH', \$this->url(\$path), [
                    'headers' => \$this->headers(),
                    'json' => \$data,
                    'http_errors' => false,
                ]);

                return \$this->handleResponse(\$response);
            }

            /**
             * @param string \$path
             * @return void
             */
            public function delete(\$path)
            {
                \$response = \$this->httpClient->request('DELETE', \$this->url(\$path), [
                    'headers' => \$this->headers(),
                    'http_errors' => false,
                ]);

                \$this->handleResponse(\$response);
            }

            /**
             * @param string \$path
             * @return string
             */
            private function url(\$path)
            {
                return rtrim(\$this->baseUrl, '/') . '/' . ltrim(\$path, '/');
            }

            /**
             * @return array
             */
            private function headers()
            {
                return [
                    'Authorization' => 'Bearer ' . \$this->token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ];
            }

            /**
             * Parse the response body and throw on any error status code.
             *
             * @throws SdkValidationException on 422
             * @throws SdkRequestException on any other 4xx/5xx
             * @return array
             */
            private function handleResponse(\$response)
            {
                \$statusCode = \$response->getStatusCode();
                \$body = json_decode(\$response->getBody()->getContents(), true) ?? [];

                if (\$statusCode === 422) {
                    throw new SdkValidationException(
                        \$body['message'] ?? 'The given data was invalid.',
                        422,
                        \$body['errors'] ?? []
                    );
                }

                if (\$statusCode >= 400) {
                    throw new SdkRequestException(
                        \$body['message'] ?? 'An error occurred.',
                        \$statusCode,
                        \$body['errors'] ?? []
                    );
                }

                return \$body;
            }
        }

        PHP;
    }
}
