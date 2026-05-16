<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Template for new ~/.hatfield/settings.yaml home settings files.
 *
 * When the home settings file does not exist on startup, the loader
 * creates it by writing this template. The file is intended to be
 * edited by the user, so it includes comments explaining each section.
 *
 * The template mirrors the structure of {@see config/hatfield.defaults.yaml}
 * with the AI section active (not commented out) so the user can fill in
 * provider secrets before their first run.
 */
final readonly class HomeSettingsTemplate
{
    /**
     * Return the full YAML content for a new home settings file.
     */
    public function getContent(): string
    {
        return <<<'YAML'
# Hatfield home settings — your personal configuration.
#
# This file is created automatically on first launch when it does not
# already exist. It lives at ~/.hatfield/settings.yaml and is NOT
# visible to other projects (each project has its own .hatfield/
# settings file).
#
# Precedence: built-in defaults < home settings < project settings.
# See docs/settings.md for full documentation.
#
# Path placeholders supported:
#   %kernel.project_dir%  App installation directory
#   ~                     Home directory
#   relative paths        Resolved against this file's directory

tui:
    # Available built-in themes:
    #   cyberpunk, catppuccin-mocha, nord, gruvbox-dark, oh-p-dark, tokyo-night
    #
    # Theme name — choose from a built-in theme or a custom theme from
    # one of the theme_paths directories.
    theme: cyberpunk

    # Extra search paths for custom theme YAML files.
    # Note: if uncommented, this replaces the default paths entirely,
    # not appends. Include any built-in paths you still want.
    # Default built-in paths are:
    #   '%kernel.project_dir%/config/themes'
    #   '.hatfield/themes'
    #   '~/.hatfield/themes'

# Session storage directory for agent runs.
# Defaults to .hatfield/sessions/ (ignored by git via .hatfield/.gitignore).
# sessions:
#     path: .hatfield/sessions

# ---------------------------------------------------------------------------
# AI provider and model configuration
# ---------------------------------------------------------------------------
#
# Add providers and models below to make them available to the agent.
# Home settings are the recommended place for personal API keys.
#
# Model identifiers follow the format provider_id/model_name:
#   deepseek/deepseek-v4-pro
#   llama_cpp/flash
#   zai/glm-5.1
#
# API keys use the env:VAR syntax to avoid committing secrets.
#
ai:
    # Your default model and reasoning level.
    # default_model: deepseek/deepseek-v4-pro
    # default_reasoning: medium

    # providers:
    #     deepseek:
    #         type: generic
    #         enabled: true
    #         base_url: https://api.deepseek.com
    #         api: openai-completions
    #         api_key: env:DEEPSEEK_API_KEY
    #         completions_path: /chat/completions
    #         supports_completions: true
    #         supports_embeddings: false
    #         models:
    #             deepseek-v4-pro:
    #                 name: DeepSeek V4 Pro
    #                 context_window: 1000000
    #                 max_tokens: 384000
    #                 input: [text]
    #                 tool_calling: true
    #                 reasoning: true
    #                 thinking_level_map: { minimal: high, low: high, medium: high, high: high, xhigh: max }
    #                 cost: { input: 0.435, output: 0.87, cache_read: 0.003625, cache_write: 0 }
    #             deepseek-v4-flash:
    #                 name: DeepSeek V4 Flash
    #                 context_window: 1000000
    #                 max_tokens: 384000
    #                 input: [text]
    #                 tool_calling: true
    #                 reasoning: true
    #                 thinking_level_map: { minimal: high, low: high, medium: high, high: high, xhigh: max }
    #                 cost: { input: 0.14, output: 0.28, cache_read: 0.0028, cache_write: 0 }
    #
    #     llama_cpp:
    #         type: generic
    #         enabled: true
    #         base_url: http://192.168.2.38:8052/v1
    #         api: openai-completions
    #         api_key: dummy
    #         completions_path: /chat/completions
    #         embeddings_path: /embeddings
    #         supports_completions: true
    #         supports_embeddings: false
    #         models:
    #             flash:
    #                 name: flash
    #                 context_window: 200000
    #                 max_tokens: 65536
    #                 input: [text, image]
    #                 tool_calling: true
    #                 reasoning: false
    #                 cost: { input: 0, output: 0, cache_read: 0, cache_write: 0 }
    #
    #     zai:
    #         type: generic
    #         enabled: true
    #         base_url: https://api.z.ai/api/coding/paas/v4
    #         api: openai-completions
    #         api_key: env:ZAI_API_KEY
    #         completions_path: /chat/completions
    #         supports_completions: true
    #         supports_embeddings: false
    #         compat:
    #             supports_developer_role: false
    #             supports_reasoning_effort: false
    #             thinking_format: zai
    #         models:
    #             glm-5.1:
    #                 name: GLM 5.1
    #                 context_window: 200000
    #                 max_tokens: 131072
    #                 input: [text]
    #                 tool_calling: true
    #                 reasoning: true
    #                 thinking_level_map: { minimal: enabled, low: enabled, medium: enabled, high: enabled, xhigh: enabled }
    #                 compat: { zai_tool_stream: true }
    #                 cost: { input: 0, output: 0, cache_read: 0, cache_write: 0 }
    #             glm-5v-turbo:
    #                 name: GLM 5V Turbo
    #                 context_window: 200000
    #                 max_tokens: 131072
    #                 input: [text, image]
    #                 tool_calling: true
    #                 reasoning: true
    #                 thinking_level_map: { minimal: enabled, low: enabled, medium: enabled, high: enabled, xhigh: enabled }
    #                 compat: { zai_tool_stream: true }
    #                 cost: { input: 0, output: 0, cache_read: 0, cache_write: 0 }
YAML;
    }
}
