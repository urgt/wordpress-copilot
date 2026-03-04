# Data Query Assistant — Project Rules

> WordPress agent skills in `.github/skills/` cover general WP patterns.
> These rules cover **only what is specific to this plugin**.

---

## 1. Plugin Identity

- **Plugin slug / folder**: `data-query-assistant`
- **Class / constant prefix**: `DQA_` | **function / hook / option / CSS / JS prefix**: `dqa_`
- **Text Domain**: `data-query-assistant`
- **Min WP**: 6.2 | **Min PHP**: 8.0
- **PHPCS**: `phpcs.xml` in project root — run `vendor/bin/phpcs` before every commit; zero errors required

---

## 2. Naming Conventions

- Classes: `DQA_Some_Class` (uppercase words, underscores)
- Constants: `DQA_UPPER_SNAKE` — e.g., `DQA_VERSION`
- Methods / functions: `snake_case`
- Hooks: `dqa_` prefix — e.g., `dqa_before_query`, `dqa_chat_response`
- CSS classes: `dqa-` prefix — e.g., `dqa-panel`, `dqa-messages`
- JS globals / handles: `dqa-` or `dqa_` — e.g., `dqa-chat-js`, `dqaChat`
- File names: `class-{slug}.php` for class files, kebab-case otherwise

---

## 3. Security — PHPCS Suppressions

General security rules (nonces, capability checks, sanitization, escaping, SQL) are in
`.github/skills/wp-plugin-development/references/security.md`.

Project-specific suppression rules only:

- `phpcs:ignore WordPress.Security.NonceVerification` — only when nonce is verified in a wrapper/caller; always add a justification comment
- `phpcs:ignore WordPress.DB.DirectDatabaseQuery` — only for legitimate queries against `dqa_*` custom tables
- `phpcs:ignore WordPress.DB.PreparedSQL` — only when the query is provably safe (e.g., table name from `$wpdb->prefix`)

---

## 4. Architecture Patterns

### Class design
- One class per file, `class-{slug}.php`
- Static methods + `init()` for service classes (existing codebase pattern)
- Private/protected internals; public only for API surface

### AJAX pattern (DQA-specific)

```php
public static function register_ajax() {
    add_action( 'wp_ajax_dqa_action_name', [ __CLASS__, 'handle_action_name' ] );
}

public static function handle_action_name() {
    // 1. Nonce check        — wp_verify_nonce()
    // 2. Capability check   — current_user_can( 'manage_options' )
    // 3. Sanitize input     — wp_unslash() then sanitize_*()
    // 4. Business logic
    // 5. wp_send_json_success() or wp_send_json_error()
    wp_die();
}
```

### Database
- Custom table names: `$wpdb->prefix . 'dqa_*'` — e.g., `wp_dqa_logs`, `wp_dqa_chats`
- Create tables in activation hook via `dbDelta()`
- Drop tables + delete options + delete transients in `uninstall.php`

### Enqueue
- Version parameter: always `DQA_VERSION`
- Admin assets: load only on plugin pages (check `$hook_suffix`)

### Error handling
- Log via `DQA_Logger` — never expose internal errors to users
- AJAX errors: `wp_send_json_error( [ 'message' => esc_html__( '...', 'data-query-assistant' ) ] )`
- Plugin must never cause a white screen

---

## 5. Internationalization

- Text domain: `data-query-assistant`
- Every user-facing string must use a translation function (`__()`, `_e()`, `esc_html__()`, `esc_attr__()`, `_n()`, `_x()`)
- Never concatenate translated strings — use `sprintf()`:
  ```php
  sprintf( __( 'Found %d results', 'data-query-assistant' ), $count )
  ```

---

## 6. CSS / JavaScript

### CSS
- All custom classes: `dqa-` prefix
- Theming via CSS custom properties: `--dqa-*`
- Animations: `transform` and `opacity` only (compositor-friendly)
- No `filter: blur()` on animated elements — use pre-blurred gradients
- No `!important` unless overriding WP admin styles (document why)

### JavaScript
- jQuery-wrapped IIFE (existing codebase convention):
  ```js
  (function($) {
      'use strict';
      // ...
  })(jQuery);
  ```
- Always send `_ajax_nonce` with every `$.ajax()` request
- `localStorage` keys: `dqa_` prefix
- Debounce/throttle scroll, resize, and input handlers

---

## 7. Privacy & Data (AI-specific)

- Never send real `DB_NAME` to AI — use the generic placeholder `'wordpress_db'` in prompts
- When Privacy Full Protection is enabled, exclude sensitive column names from the schema sent to the AI
- Chat history is per-user — never leak data across users
- All external AI API connections must be disclosed in `readme.txt`

---

## 8. Comments Policy

**DO comment:** complex algorithms, security decisions, every `phpcs:ignore` (with justification), section separators `/* ── Name ── */`

**DO NOT comment:** self-explanatory code, standard WP API calls, closing braces, commented-out code (delete it — Git has history)

---

## 9. Git & Release

- Commit messages: imperative mood, ≤ 72 chars subject
- Never include: `Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>`
- Version bumps: update plugin header, `DQA_VERSION` constant, **and** `readme.txt` Stable tag — all three
- Do not commit: `vendor/`, `node_modules/`, `.env`, debug logs
