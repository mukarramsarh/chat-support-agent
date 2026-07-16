<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * Immutable, dot-notation configuration read from the environment.
 *
 * Config is the single source of truth for wiring; nothing in the app should
 * read getenv() directly — inject Config instead so behaviour is testable.
 */
final class Config
{
    /** @param array<string,mixed> $items */
    public function __construct(private array $items)
    {
    }

    public static function fromEnv(): self
    {
        return new self([
            'app' => [
                'env'      => Env::get('APP_ENV', 'production'),
                'debug'    => self::toBool(Env::get('APP_DEBUG', 'false')),
                'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
                'base_path' => self::basePath(Env::get('APP_BASE_PATH', '')),
                'key'      => (string) Env::get('APP_KEY', ''),
                'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
                'ingest_async' => self::toBool(Env::get('INGEST_ASYNC', 'false')),
            ],
            'db' => [
                'host'    => Env::get('DB_HOST', '127.0.0.1'),
                'port'    => (int) Env::get('DB_PORT', '3306'),
                'name'    => Env::get('DB_NAME', 'support_ai'),
                'user'    => Env::get('DB_USER', 'root'),
                'pass'    => Env::get('DB_PASS', ''),
                'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
            ],
            'vector' => [
                'driver'         => Env::get('VECTOR_DRIVER', 'auto'),
                'pinecone_key'   => Env::get('PINECONE_API_KEY', ''),
                'pinecone_host'  => Env::get('PINECONE_INDEX_HOST', ''),
            ],
            'llm' => [
                'gemini_key'         => Env::get('GEMINI_API_KEY', ''),
                'openai_key'         => Env::get('OPENAI_API_KEY', ''),
                'anthropic_key'      => Env::get('ANTHROPIC_API_KEY', ''),
                'chat_provider'      => Env::get('CHAT_PROVIDER', 'gemini'),
                'chat_model'         => Env::get('CHAT_MODEL', 'gemini-flash-latest'),
                'utility_model'      => Env::get('UTILITY_MODEL', 'gemini-flash-lite-latest'),
                'embedding_provider' => Env::get('EMBEDDING_PROVIDER', 'openai'),
                'embedding_model'    => Env::get('EMBEDDING_MODEL', 'text-embedding-3-small'),
                'embedding_dims'     => (int) Env::get('EMBEDDING_DIMENSIONS', '1536'),
            ],
            'budget' => [
                'monthly_usd'       => (float) Env::get('MONTHLY_BUDGET_USD', '2.00'),
                'max_answer_tokens' => (int) Env::get('MAX_TOKENS_PER_ANSWER', '800'),
                'top_k'             => (int) Env::get('RETRIEVAL_TOP_K', '20'),
                'final_k'           => (int) Env::get('RETRIEVAL_FINAL_K', '5'),
                'min_score'         => (float) Env::get('RETRIEVAL_MIN_SCORE', '0.20'),
                'answer_cache'      => self::toBool(Env::get('ENABLE_ANSWER_CACHE', 'true')),
                'prompt_cache'      => self::toBool(Env::get('ENABLE_PROMPT_CACHE', 'true')),
                'enable_eval'       => self::toBool(Env::get('ENABLE_EVAL', 'true')),
                'min_confidence'    => (float) Env::get('EVAL_MIN_CONFIDENCE', '0.45'),
            ],
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->get($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default);
        return is_bool($v) ? $v : self::toBool((string) $v);
    }

    private static function toBool(?string $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** Normalise a sub-directory base path: '' or '/chatbot' (leading slash, no trailing). */
    public static function basePath(?string $value): string
    {
        $v = trim((string) $value, "/ \t");
        return $v === '' ? '' : '/' . $v;
    }

    /**
     * The effective base path: APP_BASE_PATH when set, otherwise auto-detected
     * from the running script's location. This matters because the INSTALLER is
     * itself behind the base path — without auto-detection a sub-directory
     * deploy 404s before there is any .env to configure.
     *
     * e.g. SCRIPT_NAME '/chatbot/public/index.php' → '/chatbot'
     *      SCRIPT_NAME '/index.php'                → ''      (docroot is public/)
     */
    public static function detectBasePath(): string
    {
        $explicit = (string) Env::get('APP_BASE_PATH', '');
        if (trim($explicit, "/ \t") !== '') {
            return self::basePath($explicit);
        }

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === '') {
            return '';
        }
        $dir = rtrim(dirname($script), '/');
        if (str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -strlen('/public'));
        }
        return self::basePath($dir);
    }
}
