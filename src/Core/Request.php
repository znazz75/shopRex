<?php

namespace ShopRex\Core;

/**
 * Wraps $_GET/$_POST/$_SERVER/$_COOKIE/$_FILES for one request, plus the
 * route parameters the Router extracted from the URL path. Replaces the
 * ad hoc `$_POST['field'] ?? default` + manual trim()/(int) casts that
 * used to be repeated inline on every page.
 *
 * In plain terms: this object represents "everything about the HTTP request
 * currently being handled" - what URL was visited, what method (GET/POST),
 * what form fields/query parameters were submitted, what files were
 * uploaded, and (once the Router has matched a route) what values were
 * captured from {placeholder} segments in the URL. Wrapping the raw PHP
 * superglobals in one object means controllers don't touch $_GET/$_POST
 * directly, which makes it much easier to see everywhere a piece of user
 * input is actually read.
 */
final class Request
{
    /** The {placeholder} values extracted from the matched route's URL pattern (e.g. ['slug' => 'classic-t-shirt']) - empty until Router::dispatch() calls withRouteParams() after a successful match. */
    /** @var array<string, string> */
    private array $routeParams = [];

    public function __construct(private readonly Session $session)
    {
    }

    public function session(): Session
    {
        return $this->session;
    }

    /** Returns the HTTP method of this request (GET, POST, ...), always uppercase. */
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Path only, query string stripped, trailing slash normalized (except
     * for the root "/" itself), leading "/shopRex" sub-directory prefix
     * (or whatever base path the install lives under) stripped so route
     * patterns never need to know about it.
     *
     * The prefix to strip is derived from SITE_URL's own path component,
     * NOT dirname($_SERVER['SCRIPT_NAME']) - the latter looks right when
     * every request is handled by one script at the install root, but
     * admin/index.php lives one directory deeper than index.php while both
     * front controllers dispatch against route patterns that already
     * include "/admin" (e.g. "/admin/orders/{id}"). Under the .htaccess
     * rewrite, SCRIPT_NAME for an admin request is ".../admin/index.php",
     * so dirname() would strip "/shopRex/admin" instead of just
     * "/shopRex" and every admin route pattern would stop matching.
     * SITE_URL is the one thing both front controllers agree on.
     */
    public function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        // PHP_URL_PATH strips off any "?query=string" so route matching
        // never has to account for it.
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Figure out how much of the path is "the folder shopRex is
        // installed under" (e.g. "/shopRex" for a subdirectory install, or
        // "" if installed at the web root) so it can be chopped off before
        // matching against route patterns like "/product/{slug}" that were
        // never written with that subdirectory in mind. See the docblock
        // above for why this comes from SITE_URL rather than SCRIPT_NAME.
        $basePath = defined('SITE_URL') ? (string)parse_url(SITE_URL, PHP_URL_PATH) : '';
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '' ) {
            $path = '/';
        }
        // Normalize away a trailing slash (e.g. "/product/foo/" ->
        // "/product/foo") so a route only has to be registered once, not
        // once with and once without the trailing slash - but never strip
        // the root path itself down to an empty string.
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        return $path;
    }

    /** Reads one query-string ($_GET) value, or $default if it wasn't sent. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** Reads one submitted form ($_POST) value, or $default if it wasn't sent. */
    public function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /** POST value if present, else GET - convenient for handlers reachable via either verb. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    /** Returns the whole input array for this request's method - $_POST for a POST request, $_GET otherwise. */
    public function all(): array
    {
        return $this->method() === 'POST' ? $_POST : $_GET;
    }

    /** Returns the raw $_FILES entry for one uploaded file field, or null if nothing was uploaded under that name. */
    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    /** Same as file(), but for a multi-file upload field (name="photos[]") - returns an empty array instead of null when nothing was uploaded, since callers typically loop over the result. */
    public function files(string $key): array
    {
        return $_FILES[$key] ?? [];
    }

    /** Returns the visitor's IP address as reported by the web server - used for rate limiting (see Services\RateLimiter) and logging. */
    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function referer(): ?string
    {
        return $_SERVER['HTTP_REFERER'] ?? null;
    }

    /** Reads one HTTP request header by its normal dashed name (e.g. "X-Requested-With") - translates it to the "HTTP_X_REQUESTED_WITH" form PHP actually stores headers under in $_SERVER. */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    /** Stores the {placeholder} values a matched Route extracted from the URL - called once by Route::invoke(), not meant to be called from application code. */
    public function withRouteParams(array $params): static
    {
        $this->routeParams = $params;
        return $this;
    }

    /** Reads one value captured from the URL by the matched route's {placeholder} (e.g. routeParam('slug') for a route registered as "/product/{slug}"). */
    public function routeParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }
}
