<?php

return [
    'openai' => ['label' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1', 'api_format' => 'responses'],
    'anthropic' => ['label' => 'Anthropic / Claude', 'base_url' => 'https://api.anthropic.com/v1', 'api_format' => 'anthropic_messages'],
    'gemini' => ['label' => 'Google Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'api_format' => 'chat_completions'],
    'openrouter' => ['label' => 'OpenRouter', 'base_url' => 'https://openrouter.ai/api/v1', 'api_format' => 'chat_completions'],
    'deepseek' => ['label' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com', 'api_format' => 'chat_completions'],
    'groq' => ['label' => 'Groq', 'base_url' => 'https://api.groq.com/openai/v1', 'api_format' => 'chat_completions'],
    'ollama' => ['label' => 'Ollama (lokal)', 'base_url' => 'http://127.0.0.1:11434/v1', 'api_format' => 'chat_completions'],
    'lmstudio' => ['label' => 'LM Studio (lokal)', 'base_url' => 'http://127.0.0.1:1234/v1', 'api_format' => 'chat_completions'],
    'local' => ['label' => 'LLM lokal lain / vLLM / llama.cpp', 'base_url' => '', 'api_format' => 'chat_completions'],
    'custom' => ['label' => 'Custom endpoint / API kompatibel', 'base_url' => '', 'api_format' => 'chat_completions'],
];
