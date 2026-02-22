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
        use GuzzleHttp\\Exception\\GuzzleException;

        class SdkConnector
        {
            private ClientInterface \$httpClient;

            public function __construct(
                private string \$baseUrl,
                private string \$token,
                ?ClientInterface \$httpClient = null,
            ) {
                \$this->httpClient = \$httpClient ?? new Client();
            }

            /**
             * @return array<string, mixed>
             */
            public function get(string \$path): array
            {
                \$response = \$this->httpClient->request('GET', \$this->url(\$path), [
                    'headers' => \$this->headers(),
                ]);

                return json_decode(\$response->getBody()->getContents(), true);
            }

            /**
             * @param  array<string, mixed>  \$data
             * @return array<string, mixed>
             */
            public function post(string \$path, array \$data): array
            {
                \$response = \$this->httpClient->request('POST', \$this->url(\$path), [
                    'headers' => \$this->headers(),
                    'json' => \$data,
                ]);

                return json_decode(\$response->getBody()->getContents(), true);
            }

            /**
             * @param  array<string, mixed>  \$data
             * @return array<string, mixed>
             */
            public function put(string \$path, array \$data): array
            {
                \$response = \$this->httpClient->request('PUT', \$this->url(\$path), [
                    'headers' => \$this->headers(),
                    'json' => \$data,
                ]);

                return json_decode(\$response->getBody()->getContents(), true);
            }

            public function delete(string \$path): void
            {
                \$this->httpClient->request('DELETE', \$this->url(\$path), [
                    'headers' => \$this->headers(),
                ]);
            }

            private function url(string \$path): string
            {
                return rtrim(\$this->baseUrl, '/').'/'.ltrim(\$path, '/');
            }

            /**
             * @return array<string, string>
             */
            private function headers(): array
            {
                return [
                    'Authorization' => 'Bearer '.\$this->token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ];
            }
        }

        PHP;
    }
}
