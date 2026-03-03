# WordPress Copilot — Coding Rules

**Always follow these rules when writing any code for this plugin.**

---

## 1. Plugin Identity

- **Prefix**: `WPC_` for classes/constants, `wpc_` for functions/hooks/options/CSS/JS handles
- **Text Domain**: `wordpress-copilot`
- **Min WP**: 6.0 | **Min PHP**: 8.0
- **PHPCS config**: `phpcs.xml` in project root — run `vendor/bin/phpcs` before committing

---

## 2. Security (Non-Negotiable)

### Nonce Verification
- Every AJAX handler **must** verify a nonce via `wp_verify_nonce()` or a helper that does
- Every form submission **must** verify `$_POST['_wpnonce']`
- Add `phpcs:ignore WordPress.Security.NonceVerification` only when nonce is verified in a wrapper/caller method — always add a justification comment

### Capability Checks
- Every AJAX handler and admin action **must** check `current_user_can()` before executing
- Use the most restrictive capability that makes sense (prefer `manage_options` for admin-only features)

### Input Sanitization
- **Always** `wp_unslash()` superglobals before sanitizing: `sanitize_text_field( wp_unslash( $_POST['key'] ) )`
- Use specific sanitizers: `sanitize_text_field()`, `absint()`, `sanitize_email()`, `sanitize_key()`, etc.
- For arrays use `array_map()` with the appropriate sanitizer
- Never trust `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER`, `$_COOKIE` — sanitize every field

### Output Escaping
- Escape **all** output: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, `wp_kses_post()`
- Use `esc_html__()` / `esc_html_e()` for translated strings in HTML context
- Use `esc_attr__()` / `esc_attr_e()` for translated strings in attributes
- `wp_json_encode()` instead of `json_encode()` — always

### SQL Safety
- **Always** use `$wpdb->prepare()` for any query containing variables
- Never interpolate variables directly into SQL strings
- Use `%s`, `%d`, `%f` placeholders
- Add `phpcs:ignore WordPress.DB.DirectDatabaseQuery` with justification only for legitimate custom-table queries
- Add `phpcs:ignore WordPress.DB.PreparedSQL` only when the query is provably safe (e.g., table name from `$wpdb->prefix`)

### File Operations
- Use `WP_Filesystem` API, never raw `file_get_contents()` / `file_put_contents()`
- Validate file paths — no user-controlled path traversal

---

## 3. WordPress Coding Standards

### PHP
- **Tabs** for indentation, not spaces
- **Yoda conditions**: `if ( 'value' === $var )` — constant on the left
- **Spacing**: `if ( $x )`, `function name( $param )`, `array( 'key' => 'val' )` or `[ 'key' => 'val' ]`
- Short array syntax `[]` is allowed (PHP 8.0+)
- Strict comparison `===` / `!==` — never `==` / `!=` for non-numeric values
- Early return pattern — avoid deep nesting

### Naming
- Classes: `WPC_Some_Class` (uppercase words, underscores)
- Methods/functions: `snake_case` — e.g., `get_user_data()`
- Constants: `WPC_UPPER_SNAKE` — e.g., `WPC_VERSION`
- Hooks: `wpc_` prefix — e.g., `wpc_before_query`, `wpc_chat_response`
- CSS classes: `wpc-` prefix — e.g., `wpc-panel`, `wpc-messages`
- JS globals/handles: `wpc-` or `wpc_` — e.g., `wpc-chat-js`, `wpcChat`
- File names: `class-{slug}.php` for class files, kebab-case for everything else

### Hooks & Filters
- Provide action/filter hooks at key extension points so other developers can customize behavior
- Always use `wpc_` prefix for custom hooks

---

## 4. Internationalization (i18n)

- **Every** user-facing string must be wrapped in a translation function
- `__( 'text', 'wordpress-copilot' )` for strings used in PHP logic
- `_e( 'text', 'wordpress-copilot' )` for direct echo
- `esc_html__()` / `esc_html_e()` when outputting in HTML
- `esc_attr__()` / `esc_attr_e()` when outputting in attributes
- `_n()` for plurals, `_x()` for context disambiguation
- Never concatenate translated strings — use `sprintf()`:
  ```php
  sprintf( __( 'Found %d results', 'wordpress-copilot' ), $count )
  ```

---

## 5. Comments Policy

### DO comment:
- Complex algorithms or non-obvious logic (1-2 lines explaining *why*, not *what*)
- Security-related decisions (why a certain capability is required, why input is trusted)
- `phpcs:ignore` directives — always include a brief justification
- Section separators in long files (`/* ── Section Name ── */`)

### DO NOT comment:
- Self-explanatory code
- Standard WordPress API calls
- Obvious variable assignments or conditionals
- Closing braces (`// end if`, `// end foreach`)
- Commented-out code — delete it, Git has history

---

## 6. Architecture & Patterns

### Class Design
- One class per file, file named `class-{slug}.php`
- Use `static` methods + `init()` for singleton-like service classes (existing pattern)
- Keep classes focused — single responsibility principle
- Private/protected for internal methods, public only for API surface

### AJAX Pattern
```php
public static function register_ajax() {
    add_action( 'wp_ajax_wpc_action_name', [ __CLASS__, 'handle_action_name' ] );
}

public static function handle_action_name() {
    // 1. Nonce check
    // 2. Capability check
    // 3. Sanitize input
    // 4. Business logic
    // 5. wp_send_json_success() or wp_send_json_error()
    wp_die();
}
```

### Enqueue Pattern
- Always use `wp_enqueue_script()` / `wp_enqueue_style()` with proper dependencies
- Use `wp_localize_script()` or `wp_add_inline_script()` to pass data to JS
- Version parameter: use `WPC_VERSION` constant
- Load admin assets only on plugin pages (check `$hook_suffix`)
- Load frontend assets only when widget renders

### Database
- Custom tables use `$wpdb->prefix . 'wpc_*'` naming
- Create tables only in activation hook via `dbDelta()`
- Always handle table existence gracefully
- Clean up in `uninstall.php` — drop tables, delete options, delete transients

### Error Handling
- Use `WP_Error` for function return errors
- AJAX errors: `wp_send_json_error( [ 'message' => esc_html__( '...', 'wordpress-copilot' ) ] )`
- Log errors via `WPC_Logger` — never expose internal errors to users
- Graceful degradation — plugin should never cause a white screen

---

## 7. CSS / JavaScript

### CSS
- Prefix all custom classes with `wpc-`
- Use CSS custom properties (`--wpc-*`) for theming
- No `!important` unless overriding WordPress admin styles (document why)
- Mobile-first responsive design
- Use `will-change` sparingly — only for elements that actually animate
- Avoid `filter: blur()` on animated elements — use pre-blurred gradients
- CSS animations: use `transform` and `opacity` only (compositor-friendly)

### JavaScript
- jQuery-wrapped IIFE pattern (existing codebase convention):
  ```js
  (function($) {
      'use strict';
      // ...
  })(jQuery);
  ```
- Use `$.ajax()` with `ajaxurl` — always send `_ajax_nonce`
- Never use `eval()`, `innerHTML` with unsanitized content, or `document.write()`
- Escape dynamic content before inserting into DOM
- Clean up event listeners and intervals when widget is destroyed
- Use `localStorage` with `wpc_` prefix for client-side state
- Debounce/throttle expensive event handlers (scroll, resize, input)

---

## 8. Performance

- Lazy-load assets — don't enqueue on pages where the plugin isn't active
- Minimize database queries — batch when possible, cache with transients where appropriate
- Avoid running expensive operations on every page load

---

## 9. Privacy & Data

- Never expose real database name, table names, or credentials to the client
- Use generic placeholders in AI prompts (e.g., `'wordpress_db'` instead of `DB_NAME`)
- Chat history stored per-user — never leak data across users
- Provide data cleanup in uninstall.php
- Disclose external API connections in readme.txt

---

## 10. Git & Release

- Commit messages: imperative mood, <72 chars subject, blank line before body
- Always include: `Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>`
- Don't commit: `vendor/`, `node_modules/`, `.env`, debug logs
- Version bumps: update BOTH plugin header AND `WPC_VERSION` constant AND `readme.txt` Stable tag
- Run `vendor/bin/phpcs` before every commit — zero errors required

---

## 11. WordPress.org Compliance

- No external resources loaded without disclosure (Google Fonts, CDNs, APIs)
- No tracking/analytics without explicit user consent
- No upsells in the WordPress admin UI beyond a simple settings link
- readme.txt must accurately describe all external service connections
- GPL-2.0+ compatible — all code and dependencies
- No obfuscated code
- No overriding WordPress core functionality without good reason
