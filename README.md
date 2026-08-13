# Zwernemann_Chat: AI Chat for Magento 2 (free & open source)

*Deutsche Version: [README.de.md](README.de.md)*

A free, open-source conversational AI assistant for the Magento 2 storefront. It
adds a chat widget to your shop that answers customer questions in natural
language, searches your catalog by meaning (not just keywords), and, when you
allow it, acts on the customer's behalf: add to cart, place an order, look up
order history, manage the wishlist, and more.

> Landing page: https://www.zwernemann.de/ki-chat-fur-magento-2-kostenlos-open-source/

## What this is

The classic e-commerce funnel (search, product page, cart, checkout) was designed
for shoppers who have time to browse. Many buyers, especially in B2B, do not
browse. They know what they want and they reorder the same products again and
again.

`Zwernemann_Chat` replaces that funnel with a conversation. A logged-in customer
opens the chat widget and writes in plain language, for example *"same cable reels
as last month, but three more"*. The assistant finds the product, checks the live
stock and price, and (if you allow it) places the order through Magento's own
order management. The assistant runs **inside** Magento, so it uses the same
access to customers, orders, products and inventory as the shop's own checkout.
Nothing leaves your system except the API calls to the language model and the
embedding service.

## More than a chatbot

A typical support chatbot matches keywords against a list of canned answers. This
module works very differently, and that difference is the whole point:

- **Grounded in your real catalog.** Answers come from a semantic search over your
  actual products, not from a static FAQ. A customer can write *"the blue screws
  we always use for the control cabinets"* and still land on the correct SKU.
- **Knows the customer.** For a logged-in customer the assistant sees the order
  history, saved addresses, cart and account, so it can reorder, reference past
  invoices and answer account questions.
- **Live data, not a snapshot.** Search results are enriched with current stock
  and with the price for the customer's own customer group (including tier
  prices).
- **It acts, it does not just talk.** The model returns structured tool calls that
  run against Magento's native services: add to cart, apply a coupon, place the
  order, update an address. Every tool can be switched on or off.
- **Real conversations.** The assistant keeps multi-turn context and detects when
  the customer changes topic, so a short *"yes, ten of those"* resolves to the
  product from the previous turn.
- **Transparent cost.** Every model call is logged with its token usage and price,
  visible in the admin dashboard.

## How it works

When a message arrives, it runs through a single pipeline
(`Model/MessageProcessor.php`). In short:

1. **Resolve the customer and conversation.** The sender is matched to a Magento
   customer; a conversation is opened or continued for their session.
2. **Load order history.** Recent orders are fetched so the model can reference and
   reorder them.
3. **Semantic product search (RAG).** The message (rewritten for short follow-ups)
   is embedded with Voyage and searched in Pinecone to find the most relevant
   products.
4. **Enrich the results.** Each match is topped up with live stock and with the
   customer-group price and tier prices from Magento.
5. **Build the context.** Customer data, order history, cart, search results and
   the fixed base system prompt are assembled into the model request.
6. **Ask the model.** The LLM returns a structured result: the detected intent, a
   written answer, the products to show, and any tool calls to run.
7. **Execute the tools.** Allowed tool calls run against Magento (add to cart,
   checkout, reorder, and so on). The first failure stops the chain so a partial
   order can never slip through.
8. **Persist and reply.** Both the inbound and outbound messages are stored, and
   the HTML answer is returned to the widget with inline product images.

The base system prompt (the assistant's persona and rules) is fixed and shown
read-only in the admin. You can append your own instructions without editing the
core prompt.

## Features

- **Storefront chat widget:** framework-agnostic (works on Luma and Hyvä), no
  RequireJS dependency, full-page-cache safe, shown to logged-in customers.
- **LLM answer generation** powered by Anthropic Claude.
- **Semantic product search** with Voyage embeddings and Pinecone.
- **Agentic commerce tools:** cart, checkout, reorder, order history, invoices,
  shipment tracking, wishlist, addresses, coupons and account. Every tool is
  switched individually. All tools are enabled by default; disable the ones you do
  not want.
- **Conversation storage**, an admin conversation grid, and a KPI dashboard
  (conversion rate, clarification rate, cost per message, cost by model).
- **Bilingual admin** (English and German) via `i18n`.

## Requirements

- Magento 2.4.x, PHP 8.1 or newer
- An **Anthropic** API key (the chat brain)
- A **Voyage AI** API key and a **Pinecone** API key (for product search)

All three offer free tiers or credits that are enough for evaluation.

## Installation

```bash
composer require zwernemann/magento2-chat
bin/magento module:enable Zwernemann_Chat
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

## First-time setup

1. **Get the API keys.**
   - Anthropic: https://console.anthropic.com (section *API Keys*)
   - Voyage AI: https://dashboard.voyageai.com (section *API Keys*)
   - Pinecone: https://app.pinecone.io (section *API Keys*; note your project
     region, for example `us-east-1`)

2. **Enter the keys** under *Stores → Configuration → Zwernemann → Chat*:
   - **General → Enable Chat**: Yes
   - **AI Language Model (LLM) → Active Provider**: Anthropic Claude
   - **Anthropic Claude → API Key**
   - **Voyage AI → API Key**
   - **Pinecone → API Key**, **Index Name** (for example `magento-products`) and
     **Region** (must match your Pinecone project, for example `us-east-1`)
   - **Web Chat Widget → Enable Web Chat Widget**: Yes

3. **Build the product index.** You do not create the Pinecone index by hand. The
   module creates it automatically on the first run (serverless, 1024 dimensions to
   match `voyage-3`). Run:

   ```bash
   bin/magento cc:index:products
   ```

   Use `--force` to reindex everything and ignore checksums, or
   `--store=<code|id>` to limit the run to one store. A nightly cron job
   (`cc_index_products`, 02:00) keeps the index in sync afterwards.

4. **Open the storefront**, log in as a customer, and the chat button appears in
   the bottom corner.

> Costs: you pay Anthropic, Voyage and Pinecone usage directly to those providers.
> The module logs every model call's token usage and cost in the dashboard.

## Admin navigation

- **Configuration:** Stores → Configuration → **Zwernemann → Chat**
- **Conversations:** Sales → **Chat → Conversations**. Every conversation with its
  status, customer, channel and last activity. Open one to read the full
  transcript.
- **Dashboard (KPIs):** Sales → **Chat → Dashboard**. Conversion rate,
  clarification rate, total cost, cost per message and cost by model for a
  selectable period.
- Grid button **Re-Index Products** starts a catalog reindex from the admin.

## Full configuration reference

All settings live under *Stores → Configuration → Zwernemann → Chat*. Fields can be
set per store view unless noted.

### General
| Setting | Meaning |
|---|---|
| **Enable Chat** | Master switch for the whole module. Off means no widget and no pipeline. |

### AI Language Model (LLM)
| Setting | Meaning |
|---|---|
| **Active Provider** | Which model powers the chat. The free module offers Anthropic Claude; the premium module adds Mistral AI and Google Gemini. |
| **Form of Address** | Whether the assistant addresses the customer formally (German *Sie*) or casually (*Du*). Affects salutation and sign-off. |
| **Shop Name (Signature)** | Name the assistant signs off with, for example *"Your team at Example Ltd."*. Leave empty to use the SMTP "From Name". |
| **System Prompt (Default, fixed)** | Read-only view of the built-in base prompt (the assistant's persona and rules). Not editable. |
| **System Prompt Additions** | Free text appended to the base prompt. Use it for shop-specific rules. Takes effect immediately. |
| **History Turns (Main Model)** | How many recent conversation rounds (one user plus one assistant message is one turn) are sent to the main model as context. More turns give more context at higher cost. Default 4. |
| **History Turns (Account / Order Queries)** | History turns for address, order, cart and account questions (no product search needed). Kept lower to save tokens. Default 2. |
| **History Messages (Query Builder)** | How many recent messages the fast model sees when it rewrites the search query for short follow-ups such as *"yes, ten of those"*. Default 6. |
| **History Message Max. Characters** | Per-message character cap in the model history; longer messages keep their first and last halves. Default 2000. |

### Anthropic Claude
| Setting | Meaning |
|---|---|
| **API Key** | Your Anthropic API key (encrypted). |
| **Main Model** | The model that writes answers and tool calls. Sonnet is a good balance of quality and cost. |
| **Max Tokens** | Maximum tokens per response. Default 8192. |
| **Fast Model (Query Reformulation)** | Cheaper model that rewrites search queries from short follow-ups. Default Haiku. |

### Voyage AI (Embeddings)
| Setting | Meaning |
|---|---|
| **API Key** | Your Voyage AI key (encrypted). |
| **Model** | Embedding model. Default `voyage-3` (1024 dimensions). |
| **Catalog Language** | The language your catalog is stored in. When set, the term extractor also generates synonyms and translations in this language for foreign-language queries. Leave empty to keep the customer's own language, which is recommended when customers and catalog share a language. |

### Pinecone (Vector DB)
| Setting | Meaning |
|---|---|
| **API Key** | Your Pinecone key (encrypted). |
| **Index Name** | Name of the vector index, for example `magento-products`. Created automatically on the first index run if missing. |
| **Region** | Your Pinecone serverless region, for example `us-east-1`. Must match your Pinecone project. |
| **Top K Results** | How many catalog matches the search returns per query. Default 25. |

### Product Search / Indexing
| Setting | Meaning |
|---|---|
| **Excluded Attributes** | Comma-separated attribute codes that are not sent to Pinecone (embed text and metadata). Put sensitive fields here, such as purchase prices or internal notes. After a change run a full reindex with `bin/magento cc:index:products --force`. |

### Payment & Shipping
*(Used only when the checkout tool places an order.)*
| Setting | Meaning |
|---|---|
| **Payment Method Code** | Magento payment method for AI-placed orders, for example `checkmo`, `purchaseorder` or `free`. Default `checkmo`. |
| **PO Number Mode** | For `purchaseorder`: none, ask the customer, or auto-generate a reference number. |
| **Preferred Shipping Method** | Carrier and method code joined by an underscore, for example `flatrate_flatrate`. Leave empty to use the first available method. |

### Magento REST API
| Setting | Meaning |
|---|---|
| **Base URL** | Leave empty to use this instance. Set it only for a split or headless setup. |
| **Admin Integration Token** | Encrypted token for that remote instance. |

### Web Chat Widget
| Setting | Meaning |
|---|---|
| **Enable Web Chat Widget** | Show the storefront widget (logged-in customers only). |
| **Widget Title** | Text in the chat header. |
| **Chat Button Label** | Text on the floating button. |
| **Welcome Message** | First message shown when the chat opens. |
| **Primary Color** | Hex color for header, button and accents, for example `#1a73e8`. |
| **LLM Debug Output (WebChat)** | Shows the raw model request and response as a debug block in the chat window. Turn this off in production. |

### Allowed Actions (Tool Permissions)
Each tool the assistant may use is switched here. All actions are enabled by
default, so the assistant is fully capable out of the box. To restrict it, disable
individual tools; a disabled tool is never even offered to the model. Keep in mind
that write tools (cart, checkout, address, account and so on) perform real actions
on the customer's behalf, so review them before going live.

| Tool (code) | Type | What it does |
|---|---|---|
| `search_products_by_filter` | read | Structured catalog filter search |
| `get_order_history` | read | List the customer's past orders |
| `get_order_detail` | read | Details of a single order |
| `get_shipment_tracking` | read | Tracking numbers and status |
| `get_invoice` | read | Retrieve an invoice |
| `get_shipping_addresses` | read | List saved shipping addresses |
| `get_wishlist` | read | Show the wishlist |
| `get_account_info` | read | Show account data |
| `cart_add_item` | write | Add an item to the cart |
| `cart_update_item` | write | Change a cart line quantity |
| `cart_remove_item` | write | Remove a cart line |
| `cart_checkout` | write | Place the order and check out |
| `reorder_from_history` | write | Reorder a previous order |
| `add_shipping_address` | write | Add a new shipping address |
| `set_order_shipping_address` | write | Change an order's shipping address |
| `apply_coupon_code` / `remove_coupon_code` | write | Coupon handling |
| `wishlist_add_item` / `wishlist_remove_item` / `wishlist_move_to_cart` | write | Wishlist changes |
| `update_account_info` | write | Change account data |
| `toggle_newsletter` | write | Subscribe or unsubscribe from the newsletter |
| `set_stock_notification` | write | Back-in-stock notification |

## CLI commands

| Command | Purpose |
|---|---|
| `bin/magento cc:index:products` | Index all products into Pinecone. `--force` ignores checksums, `--store=<code\|id>` limits the run to one store. |

The cron job `cc_index_products` reindexes changed products nightly at 02:00.

## Premium extension

The commercial module **`zwernemann/module-conversional-commerce`** builds on this
base and adds:

- **More LLM providers:** Mistral AI (GDPR-friendly, EU data centres) and Google
  Gemini Flash, selectable in the same **Active Provider** dropdown.
- **E-mail channel:** customers order by e-mail. An IMAP poller ingests messages,
  including Excel, PDF and Word attachments, and replies by SMTP.
- **WhatsApp channel:** inbound REST API plus a connector.
- **Human escalation:** confidence, keyword and order-value triggers pause the AI,
  notify an admin, and require approval before the conversation continues.
- **GDPR and data deletion:** anonymization and hard-delete tooling, plus a
  customer self-service deletion endpoint.

It plugs into this module through DI extension seams (`EscalationHandlerInterface`,
`ErrorNotifierInterface`, the MessageProcessor channel map and the LLM provider
pool), so the free module runs on its own without it.

## Architecture note

The free module owns the message-processing pipeline (`MessageProcessor`) and the
storage, LLM, RAG and tool layers. Premium behaviour is injected through
well-defined seams rather than baked in, which is why installing or removing the
premium module never touches this module's code.

## Version History

### 1.0.1

- Initial release of the free, open-source AI chat module, carved out of the Zwernemann ConversationalCommerce engine.
- WebChat widget, semantic product search (RAG), agentic commerce tools, conversation storage and a KPI dashboard.
- Anthropic Claude as the built-in provider; Mistral and Gemini available through the premium module.
- Tested with Magento 2.4.6 to 2.4.8-p1.

## Contact & Support

**Zwernemann Medienentwicklung**\
Martin Zwernemann\
79730 Murg, Germany

[To the website](https://www.zwernemann.de/ki-chat-fur-magento-2-kostenlos-open-source/)

If you have questions, problems, or ideas for new features, feel free to get in touch.

## License

OSL-3.0. See [`LICENSE`](LICENSE).
