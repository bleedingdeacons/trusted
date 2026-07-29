<?php

declare(strict_types=1);

/**
 * WordPress REST/HTTP class stubs for the Trusted tests.
 *
 * The REST controller type-hints WP_REST_Request/Response, returns WP_Error and
 * reads WP_REST_Server method constants. WP_Mock supplies the *functions* the
 * classes call; these are the *classes* it does not provide. Minimal but
 * behaviour-compatible with the parts the controller uses.
 */

if (!defined('TRUSTED_TEMPLATE_POST_TYPE')) {
    define('TRUSTED_TEMPLATE_POST_TYPE', 'trusted_template');
}
if (!defined('TRUSTED_VERSION')) {
    define('TRUSTED_VERSION', '1.0.0-test');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('current_time')) {
    function current_time(string $type, int $gmt = 0): string
    {
        return $type === 'timestamp' ? (string) time() : gmdate('Y-m-d H:i:s');
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, $value): bool
    {
        $GLOBALS['trusted_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['trusted_options'][$name]);
        return true;
    }
}
if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', bool $execute = true): array
    {
        $GLOBALS['trusted_dbdelta'][] = $queries;
        return [];
    }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE  = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE  = 'PUT, PATCH';
        public const DELETABLE = 'DELETE';
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string,mixed> */
        public array $data;

        public function __construct(private string $code = '', private string $message = '', array $data = [])
        {
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return array<string,mixed> */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(private mixed $data = null, private int $status = 200)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        /** @var array<string,string> */
        public array $headers = [];

        public function header(string $key, string $value): void
        {
            $this->headers[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Backed by a params array; supports both array access ($request['id'])
     * and get_param(), which is all the controller uses.
     *
     * @implements ArrayAccess<string,mixed>
     */
    class WP_REST_Request implements ArrayAccess
    {
        /** @param array<string,mixed> $params */
        public function __construct(private array $params = [], private string $route = '')
        {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->params[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->params[$offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->params[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->params[$offset]);
        }
    }
}

// Pure passthrough helpers the controller/services call. Left to plain globals
// (not WP_Mock) since no test asserts on them; apply_filters / current_user_can
// stay with WP_Mock so the existing filter-based tests keep working.
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str): string
    {
        return trim(strip_tags((string) $str));
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str): string
    {
        return trim(strip_tags((string) $str));
    }
}
if (!function_exists('add_action')) {
    function add_action(string $hook, $cb, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(string $hook, $cb, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}
if (!function_exists('acf_add_local_field_group')) {
    function acf_add_local_field_group(array $group): bool
    {
        $GLOBALS['trusted_acf_groups'][] = $group;
        return true;
    }
}
if (!function_exists('acf_maybe_get_POST')) {
    function acf_maybe_get_POST(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}
if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): void
    {
    }
}
if (!function_exists('register_post_type')) {
    function register_post_type(string $type, array $args = [])
    {
        $GLOBALS['trusted_post_types'][$type] = $args;
        return (object) ['name' => $type];
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route(string $ns, string $route, array $args = [], bool $override = false): bool
    {
        $GLOBALS['trusted_rest_routes'][] = $route;
        return true;
    }
}
