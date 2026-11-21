<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

use JsonException;

/**
 * What a server answered. Untrusted data, whatever its status.
 */
final class HttpResponse
{
    /**
     * @param int $status HTTP status.
     * @param array<string, array<string>> $headers Header name => values, as the server sent them.
     * @param string $body The body.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * @param string $name Header name, in any case.
     * @return string|null Its values joined with ", ", or null when the server did not send it.
     */
    public function header(string $name): ?string
    {
        $values = null;
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                $values = array_merge($values ?? [], $value);
            }
        }

        return $values === null ? null : implode(', ', $values);
    }

    /**
     * @return array<string, mixed>|null The body as a JSON object, or null when it is not one.
     */
    public function json(): ?array
    {
        try {
            $decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return bool Whether it is a redirect that may carry a Location.
     */
    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }
}
