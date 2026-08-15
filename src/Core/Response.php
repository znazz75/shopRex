<?php

namespace ShopRex\Core;

/**
 * The only place headers get sent / body gets echoed at the top level.
 * Controllers return a Response instead of echoing directly; index.php /
 * admin/index.php just call ->send() once, at the very end.
 *
 * In plain terms: this is an envelope for "what to send back to the
 * browser" - a body (HTML/JSON/nothing), an HTTP status code (200 OK, 404
 * Not Found, etc.), and any headers (like where to redirect to). Building
 * this envelope in a controller and only actually sending it at the very
 * end (in the front controller) means nothing gets written to the browser
 * before all the logic has finished, which avoids bugs like "headers
 * already sent" errors and makes controllers easy to test/reason about.
 */
final class Response
{
    /** HTTP response headers to send, keyed by header name (e.g. 'Content-Type' => 'text/html; charset=UTF-8'). */
    /** @var array<string, string> */
    private array $headers = [];

    /** Constructor is private - callers must use one of the named factory methods below (html(), json(), redirect(), ...) so it's always clear what kind of response is being built. */
    private function __construct(
        private string $body,
        private int $status = 200,
    ) {
    }

    /** Builds a normal HTML page response - this is what nearly every storefront/admin page uses. */
    public static function html(string $body, int $status = 200): self
    {
        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $response;
    }

    /** Builds a JSON response (used by AJAX/API-style endpoints) - encodes $data to a JSON string and sets the matching Content-Type header. */
    public static function json(mixed $data, int $status = 200): self
    {
        $response = new self((string)json_encode($data), $status);
        $response->headers['Content-Type'] = 'application/json; charset=UTF-8';
        return $response;
    }

    /** Builds a "go to a different URL" response with an empty body and a Location header - status 302 (temporary) by default, but callers can pass e.g. 301 for a permanent redirect. */
    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self('', $status);
        $response->headers['Location'] = $url;
        return $response;
    }

    /** Shortcut for a 404 "page doesn't exist" HTML response. */
    public static function notFound(string $body = '404 Not Found'): self
    {
        return self::html($body, 404);
    }

    /** Shortcut for a 405 response - used when a URL matched a route's path but not its allowed HTTP method (e.g. a GET-only page requested via POST). */
    public static function methodNotAllowed(string $body = '405 Method Not Allowed'): self
    {
        return self::html($body, 405);
    }

    /** Adds/overwrites one header and returns $this, so calls can be chained (e.g. Response::html($body)->withHeader(...)). */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** Actually writes the response to the browser: sets the HTTP status line, emits every header, then echoes the body. Must only be called once, at the very end of the request (see index.php / admin/index.php). */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
