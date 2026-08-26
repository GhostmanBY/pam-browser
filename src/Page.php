<?php

declare(strict_types=1);

namespace Pam\Browser;

use JsonException;
use Pam\Browser\Exception\ProtocolException;
use Pam\Browser\Protocol\Event;

final class Page
{
    /** @var null|array{username: string, password: string} */
    private ?array $proxyCredentials = null;

    /** @var null|array{url: string, html: string} */
    private ?array $cachedDocument = null;

    private bool $fetchListenersInstalled = false;

    public function __construct(
        private readonly Connection $connection,
        public readonly string $targetId,
        public readonly string $sessionId,
    ) {
    }

    public function enable(): void
    {
        $this->connection->command('Page.enable', sessionId: $this->sessionId);
        $this->connection->command('Runtime.enable', sessionId: $this->sessionId);
    }

    public function navigate(string $url, float $timeoutSeconds = 30.0): void
    {
        if ($url === '' || str_contains($url, "\0")) {
            throw new ProtocolException('Page URL must be non-empty and contain no NUL bytes.');
        }

        $result = $this->connection->command(
            'Page.navigate',
            ['url' => $url],
            $this->sessionId,
            $timeoutSeconds,
        );
        $errorText = $result['errorText'] ?? null;
        if (is_string($errorText) && $errorText !== '') {
            throw new ProtocolException('Page navigation failed: '.$errorText);
        }

        $this->connection->waitForEvent(
            'Page.loadEventFired',
            fn (Event $event): bool => $event->sessionId === $this->sessionId,
            $timeoutSeconds,
        );
    }

    public function evaluate(string $expression, bool $awaitPromise = true): mixed
    {
        if ($expression === '') {
            throw new ProtocolException('JavaScript expression cannot be empty.');
        }

        $result = $this->connection->command('Runtime.evaluate', [
            'expression' => $expression,
            'awaitPromise' => $awaitPromise,
            'returnByValue' => true,
            'userGesture' => true,
        ], $this->sessionId);

        if (isset($result['exceptionDetails']) && is_array($result['exceptionDetails'])) {
            $details = $result['exceptionDetails'];
            $exception = $details['exception'] ?? null;
            $description = is_array($exception) && is_string($exception['description'] ?? null)
                ? $exception['description']
                : $details['text'] ?? 'Unknown JavaScript exception';
            throw new ProtocolException(sprintf(
                'JavaScript evaluation failed: %s',
                is_string($description) ? $description : 'Unknown JavaScript exception',
            ));
        }

        $remoteObject = $result['result'] ?? null;
        if (!is_array($remoteObject)) {
            throw new ProtocolException('CDP returned an invalid Runtime.evaluate result.');
        }
        if (($remoteObject['type'] ?? null) === 'undefined') {
            return null;
        }

        return $remoteObject['value'] ?? null;
    }

    public function addScriptOnNewDocument(string $source): string
    {
        if ($source === '') {
            throw new ProtocolException('New-document script cannot be empty.');
        }

        $result = $this->connection->command(
            'Page.addScriptToEvaluateOnNewDocument',
            ['source' => $source],
            $this->sessionId,
        );
        $identifier = $result['identifier'] ?? null;
        if (!is_string($identifier) || $identifier === '') {
            throw new ProtocolException('CDP did not return a new-document script identifier.');
        }

        return $identifier;
    }

    public function setBypassCsp(bool $enabled): void
    {
        $this->connection->command('Page.setBypassCSP', ['enabled' => $enabled], $this->sessionId);
    }

    public function setUserAgent(string $userAgent): void
    {
        if ($userAgent === '' || str_contains($userAgent, "\0")) {
            throw new ProtocolException('User agent must be non-empty and contain no NUL bytes.');
        }
        $this->connection->command(
            'Network.setUserAgentOverride',
            ['userAgent' => $userAgent],
            $this->sessionId,
        );
    }

    public function captureScreenshot(): string
    {
        $result = $this->connection->command(
            'Page.captureScreenshot',
            ['format' => 'png', 'fromSurface' => true, 'captureBeyondViewport' => false],
            $this->sessionId,
        );
        $data = $result['data'] ?? null;
        if (!is_string($data) || $data === '') {
            throw new ProtocolException('CDP did not return screenshot data.');
        }
        $decoded = base64_decode($data, true);
        if (!is_string($decoded) || !str_starts_with($decoded, "\x89PNG\r\n\x1a\n")) {
            throw new ProtocolException('CDP returned an invalid PNG screenshot.');
        }

        return $decoded;
    }

    public function authenticate(string $username, string $password): void
    {
        if ($username === '' || str_contains($username.$password, "\0")) {
            throw new ProtocolException('Proxy credentials are invalid.');
        }
        $this->proxyCredentials = ['username' => $username, 'password' => $password];
        $this->configureFetch();
    }

    public function serveDocument(string $url, string $html): void
    {
        if ($url === '' || $html === '') {
            throw new ProtocolException('Document URL and HTML must be non-empty.');
        }
        $this->cachedDocument = ['url' => $url, 'html' => $html];
        $this->configureFetch();
    }

    /** @param callable(string): void $callback */
    public function captureDocument(string $url, callable $callback): void
    {
        if ($url === '') {
            throw new ProtocolException('Document URL must be non-empty.');
        }
        $requestIds = [];
        $this->connection->on('Network.responseReceived', function (Event $event) use ($url, &$requestIds): void {
            if ($event->sessionId !== $this->sessionId) return;
            $response = $event->parameters['response'] ?? null;
            $requestId = $event->parameters['requestId'] ?? null;
            if (is_array($response) && ($response['url'] ?? null) === $url && is_string($requestId)) {
                $requestIds[$requestId] = true;
            }
        });
        $this->connection->on('Network.loadingFinished', function (Event $event) use (&$requestIds, $callback): void {
            if ($event->sessionId !== $this->sessionId) return;
            $requestId = $event->parameters['requestId'] ?? null;
            if (!is_string($requestId) || !isset($requestIds[$requestId])) return;
            unset($requestIds[$requestId]);
            $result = $this->connection->command(
                'Network.getResponseBody',
                ['requestId' => $requestId],
                $this->sessionId,
            );
            $body = $result['body'] ?? null;
            if (!is_string($body)) return;
            if (($result['base64Encoded'] ?? false) === true) {
                $decoded = base64_decode($body, true);
                if (!is_string($decoded)) return;
                $body = $decoded;
            }
            $callback($body);
        });
        $this->connection->command('Network.enable', sessionId: $this->sessionId);
    }

    private function configureFetch(): void
    {
        if (!$this->fetchListenersInstalled) {
            $this->fetchListenersInstalled = true;
            $this->connection->on('Fetch.authRequired', function (Event $event): void {
                if ($event->sessionId !== $this->sessionId || $this->proxyCredentials === null) return;
                $requestId = $event->parameters['requestId'] ?? null;
                if (!is_string($requestId)) return;
                $this->connection->command('Fetch.continueWithAuth', [
                    'requestId' => $requestId,
                    'authChallengeResponse' => [
                        'response' => 'ProvideCredentials',
                        'username' => $this->proxyCredentials['username'],
                        'password' => $this->proxyCredentials['password'],
                    ],
                ], $this->sessionId);
            });
            $this->connection->on('Fetch.requestPaused', function (Event $event): void {
                if ($event->sessionId !== $this->sessionId) return;
                $requestId = $event->parameters['requestId'] ?? null;
                if (!is_string($requestId)) return;
                $request = $event->parameters['request'] ?? null;
                $requestUrl = is_array($request) ? ($request['url'] ?? null) : null;
                if ($this->cachedDocument !== null
                    && $requestUrl === $this->cachedDocument['url']
                    && ($event->parameters['resourceType'] ?? null) === 'Document'
                ) {
                    $this->connection->command('Fetch.fulfillRequest', [
                        'requestId' => $requestId,
                        'responseCode' => 200,
                        'responseHeaders' => [[
                            'name' => 'Content-Type',
                            'value' => 'text/html; charset=utf-8',
                        ]],
                        'body' => base64_encode($this->cachedDocument['html']),
                    ], $this->sessionId);

                    return;
                }
                $this->connection->command('Fetch.continueRequest', ['requestId' => $requestId], $this->sessionId);
            });
        }
        $parameters = ['handleAuthRequests' => $this->proxyCredentials !== null];
        if ($this->cachedDocument !== null) {
            $parameters['patterns'] = [[
                'urlPattern' => $this->cachedDocument['url'],
                'resourceType' => 'Document',
                'requestStage' => 'Request',
            ]];
        }
        $this->connection->command('Fetch.enable', $parameters, $this->sessionId);
    }

    /** @param callable(mixed): void $callback */
    public function exposeBinding(string $name, callable $callback): void
    {
        if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name) !== 1) {
            throw new ProtocolException('JavaScript binding name is invalid.');
        }

        $this->connection->command(
            'Runtime.addBinding',
            ['name' => $name],
            $this->sessionId,
        );
        $this->connection->on('Runtime.bindingCalled', function (Event $event) use ($name, $callback): void {
            if ($event->sessionId !== $this->sessionId || ($event->parameters['name'] ?? null) !== $name) {
                return;
            }
            $payload = $event->parameters['payload'] ?? null;
            if (!is_string($payload)) {
                throw new ProtocolException('JavaScript binding payload must be a string.');
            }

            try {
                $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new ProtocolException('JavaScript binding payload must contain JSON.', previous: $exception);
            }
            $callback($decoded);
        });
    }

    public function pump(float $timeoutSeconds = 0.0): bool
    {
        return $this->connection->pump($timeoutSeconds);
    }

    public function waitForFunction(
        string $expression,
        float $timeoutSeconds = 30.0,
        float $pollIntervalSeconds = 0.05,
    ): mixed {
        if ($pollIntervalSeconds <= 0.0 || $pollIntervalSeconds > $timeoutSeconds) {
            throw new ProtocolException('JavaScript polling interval must be positive and fit the timeout.');
        }

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $value = $this->evaluate($expression);
            if ($value !== false && $value !== null) {
                return $value;
            }
            self::delay($pollIntervalSeconds);
        } while (microtime(true) < $deadline);

        throw new ProtocolException('Timed out waiting for a JavaScript condition.');
    }

    private static function delay(float $seconds): void
    {
        if (function_exists('Pam\\Async\\delay')) {
            \Pam\Async\delay($seconds);
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }
}
