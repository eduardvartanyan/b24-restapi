<?php

namespace App\Support;

class MessageCatalog
{
    private array $messages = [];

    public function __construct(string $file)
    {
        $messages = require $file;

        if (!is_array($messages)) {
            throw new \RuntimeException("Message file must return array: {$file}");
        }

        $this->messages = $messages;
    }

    public function get(string $key, array $params = []): string
    {
        if (!array_key_exists($key, $this->messages)) {
            throw new \InvalidArgumentException("Message key not found: {$key}");
        }

        $message = $this->messages[$key];

        foreach ($params as $name => $value) {
            $message = str_replace('{' . $name . '}', (string)$value, $message);
        }

        return $message;
    }
}