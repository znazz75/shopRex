<?php

namespace ShopRex\Core;

/**
 * The only place headers get sent / body gets echoed at the top level.
 * Controllers return a Response instead of echoing directly; index.php /
 * admin/index.php just call ->send() once, at the very end.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private function __construct(
        private string $body,
        private int $status = 200,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $response;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $response = new self((string)json_encode($data), $status);
        $response->headers['Content-Type'] = 'application/json; charset=UTF-8';
        return $response;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self('', $status);
        $response->headers['Location'] = $url;
        return $response;
    }

    public static function notFound(string $body = '404 Not Found'): self
    {
        return self::html($body, 404);
    }

    public static function methodNotAllowed(string $body = '405 Method Not Allowed'): self
    {
        return self::html($body, 405);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
