=== WordPress Copilot ===
Contributors: gayraturinbaev
Tags: ai, database, sql, analytics, chatbot
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered natural language database assistant. Ask anything about your WordPress data in plain language — get instant SQL-powered answers.

== Description ==

**WordPress Copilot** is an AI-powered assistant that lets you query your WordPress database using plain English. No SQL knowledge required.

Ask questions like:
* "Top 10 best-selling products this month"
* "New users registered this week"
* "Total revenue last 30 days"
* "Products with stock below 5 units"

The plugin translates your questions into safe, read-only SQL SELECT queries, runs them against your database, and presents the results in a clean table — all from inside the WordPress admin.

**Key Features:**

* 🤖 **Multiple AI providers** — Anthropic (Claude), OpenAI (GPT), Google (Gemini)
* 🔒 **Read-only by default** — Only SELECT queries are ever executed
* 📊 **Streaming responses** — Real-time token streaming via Server-Sent Events
* 💬 **Persistent chat history** — Conversations are saved per-user per-provider
* 🔐 **Privacy controls** — Mask sensitive columns (email, phone, passwords) in results and AI context
* 👥 **Role-based access** — Restrict access to specific WordPress roles
* ⚡ **Schema caching** — DB schema cached as transients for performance
* 🛒 **WooCommerce & EDD aware** — Automatic hints for product/order structure
* 🎙️ **Voice input** — Optional speech-to-text input (browser Web Speech API)

= External Services =

This plugin communicates with third-party AI APIs to process your queries. **By using this plugin, data is sent to the AI provider you configure.**

**What is sent:**
1. Your WordPress database schema (table names, column names, data types) — sent on every request
2. Your natural language question — sent on every request
3. Query result rows — sent for AI-powered result summarization (up to 30 rows)

**Which services may receive this data:**

* **Anthropic** — if you use Claude models. [Privacy Policy](https://www.anthropic.com/privacy) | [Terms of Service](https://www.anthropic.com/terms)
* **OpenAI** — if you use GPT models. [Privacy Policy](https://openai.com/privacy/) | [Terms of Service](https://openai.com/terms/)
* **Google (Gemini)** — if you use Gemini models. [Privacy Policy](https://policies.google.com/privacy) | [Terms of Service](https://policies.google.com/terms)

Data is only sent when you submit a query in the Copilot chat panel. No data is sent passively or in the background. You choose which AI provider to use in Settings → WP Copilot.

= Privacy =

Enable the **Privacy Protection** setting to mask sensitive column values (email addresses, phone numbers, names, passwords) before they are sent to the AI provider. The "Full Protection" level also removes sensitive column names from the schema sent to the AI.

== Installation ==

1. Upload the `wordpress-copilot` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → WP Copilot** and configure your AI provider and API key
4. The Copilot chat panel appears in the bottom-right corner of every admin page

= Requirements =

* WordPress 6.0 or higher
* PHP 8.0 or higher
* A valid API key for at least one supported AI provider (Anthropic, OpenAI, or Google)

== Frequently Asked Questions ==

= Is my database data safe? =

The plugin only runs SELECT (read-only) queries. INSERT, UPDATE, DELETE, DROP, ALTER, and all other write operations are explicitly blocked at the application level. Your data cannot be modified through this plugin.

= Who can use the Copilot chat? =

By default, only users with the **Administrator** role can access the chat. You can expand or restrict this to other roles in **Settings → WP Copilot → Access**.

= Where is my API key stored? =

Your API key is stored in the WordPress options table (`wp_options`) in the standard WordPress way. It is never transmitted anywhere other than to the AI provider you have selected.

= Does this work with WooCommerce? =

Yes. WordPress Copilot automatically detects WooCommerce and provides the AI with optimized hints for querying products, orders, customers, and revenue data.

= Can I prevent sensitive data from being sent to the AI? =

Yes. Enable **Privacy Protection** in the settings to mask sensitive column values (emails, phone numbers, names, addresses) before they reach the AI. "Full Protection" also hides those column names from the schema.

= Does the plugin work on multisite? =

The plugin is not tested for multisite and currently operates on a single site only.

== Screenshots ==

1. The Copilot chat panel in the WordPress admin
2. Query results displayed as a table
3. Settings page — AI provider configuration
4. Settings page — Privacy controls

== Changelog ==

= 1.0.0 =
* Initial public release
* Support for Anthropic (Claude), OpenAI (GPT), and Google (Gemini)
* Real-time streaming via Server-Sent Events
* Persistent chat history per user
* Privacy protection with configurable column masking
* WooCommerce and Easy Digital Downloads schema hints
* Role-based access control
* Schema caching with configurable TTL
* Voice input support (Web Speech API)
* Query logging

== Upgrade Notice ==

= 1.0.0 =
Initial release.
