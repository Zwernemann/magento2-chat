# Zwernemann_Chat: KI-Chat für Magento 2 (kostenlos & Open Source)

*English version: [README.md](README.md)*

Ein kostenloser, quelloffener KI-Assistent für den Magento-2-Storefront. Er
ergänzt den Shop um ein Chat-Widget, das Kundenfragen in natürlicher Sprache
beantwortet, den Katalog nach Bedeutung durchsucht (nicht nur nach Stichworten)
und, wenn Sie es erlauben, im Namen des Kunden handelt: in den Warenkorb legen,
eine Bestellung aufgeben, die Bestellhistorie abrufen, die Wunschliste verwalten
und mehr.

> Landingpage: https://www.zwernemann.de/ki-chat-fur-magento-2-kostenlos-open-source/

## Worum es geht

Der klassische E-Commerce-Ablauf (Suche, Produktseite, Warenkorb, Kasse) ist für
Kunden gedacht, die Zeit zum Stöbern haben. Viele Einkäufer, gerade im B2B,
stöbern aber nicht. Sie wissen, was sie brauchen, und bestellen dieselben Produkte
immer wieder.

`Zwernemann_Chat` ersetzt diesen Ablauf durch ein Gespräch. Ein eingeloggter Kunde
öffnet das Chat-Widget und schreibt in normaler Sprache, zum Beispiel *"die
gleichen Kabeltrommeln wie letzten Monat, aber drei Stück mehr"*. Der Assistent
findet das Produkt, prüft Live-Bestand und Preis und gibt (sofern erlaubt) die
Bestellung über Magentos eigene Auftragsverwaltung auf. Der Assistent läuft
**innerhalb** von Magento und nutzt denselben Zugriff auf Kunden, Bestellungen,
Produkte und Lager wie die Kasse des Shops. Ihr System verlässt nichts außer den
API-Aufrufen an das Sprachmodell und den Embedding-Dienst.

## Mehr als ein Chatbot

Ein üblicher Support-Chatbot gleicht Stichworte mit einer Liste vorgefertigter
Antworten ab. Dieses Modul arbeitet grundlegend anders, und genau das ist der
Punkt:

- **Im echten Katalog verankert.** Antworten stammen aus einer semantischen Suche
  über Ihre tatsächlichen Produkte, nicht aus einer statischen FAQ. Ein Kunde kann
  *"die blauen Schrauben, die wir immer für die Schaltschränke nehmen"* schreiben
  und landet trotzdem bei der richtigen SKU.
- **Kennt den Kunden.** Für einen eingeloggten Kunden sieht der Assistent
  Bestellhistorie, gespeicherte Adressen, Warenkorb und Konto. So kann er
  nachbestellen, auf frühere Rechnungen verweisen und Kontofragen beantworten.
- **Live-Daten statt Momentaufnahme.** Suchergebnisse werden mit dem aktuellen
  Lagerbestand und dem Preis der Kundengruppe (inklusive Staffelpreisen)
  angereichert.
- **Er handelt, statt nur zu reden.** Das Modell liefert strukturierte Tool-Calls,
  die gegen Magentos native Dienste laufen: in den Warenkorb legen, Gutschein
  einlösen, Bestellung aufgeben, Adresse ändern. Jedes Tool lässt sich einzeln
  ein- oder ausschalten.
- **Echte Gespräche.** Der Assistent hält den Mehr-Turn-Kontext und erkennt einen
  Themenwechsel, sodass ein kurzes *"ja, zehn davon"* sich auf das Produkt aus dem
  vorherigen Turn bezieht.
- **Transparente Kosten.** Jeder Modellaufruf wird mit Tokenverbrauch und Preis
  protokolliert und ist im Admin-Dashboard sichtbar.

## Wie es funktioniert

Trifft eine Nachricht ein, durchläuft sie eine einzige Pipeline
(`Model/MessageProcessor.php`). In Kurzform:

1. **Kunde und Konversation auflösen.** Der Absender wird einem Magento-Kunden
   zugeordnet; für seine Session wird eine Konversation eröffnet oder fortgesetzt.
2. **Bestellhistorie laden.** Aktuelle Bestellungen werden geladen, damit das
   Modell darauf verweisen und nachbestellen kann.
3. **Semantische Produktsuche (RAG).** Die (für kurze Rückfragen umformulierte)
   Nachricht wird mit Voyage eingebettet und in Pinecone durchsucht, um die
   relevantesten Produkte zu finden.
4. **Ergebnisse anreichern.** Jeder Treffer wird mit Live-Bestand sowie
   Kundengruppen- und Staffelpreisen aus Magento ergänzt.
5. **Kontext aufbauen.** Kundendaten, Bestellhistorie, Warenkorb, Suchergebnisse
   und der feste Basis-System-Prompt werden zur Modellanfrage zusammengesetzt.
6. **Modell fragen.** Das LLM liefert ein strukturiertes Ergebnis: den erkannten
   Intent, eine formulierte Antwort, die anzuzeigenden Produkte und etwaige
   Tool-Calls.
7. **Tools ausführen.** Erlaubte Tool-Calls laufen gegen Magento (Warenkorb,
   Kasse, Nachbestellung und so weiter). Der erste Fehler stoppt die Kette, damit
   nie eine Teilbestellung durchrutscht.
8. **Speichern und antworten.** Ein- und ausgehende Nachricht werden gespeichert,
   die HTML-Antwort geht mit eingebetteten Produktbildern zurück ans Widget.

Der Basis-System-Prompt (Persona und Regeln des Assistenten) ist fest und wird im
Admin nur zur Ansicht angezeigt. Eigene Anweisungen können Sie anhängen, ohne den
Kern-Prompt zu verändern.

## Funktionen

- **Storefront-Chat-Widget:** frameworkunabhängig (läuft auf Luma und Hyvä), ohne
  RequireJS-Abhängigkeit, Full-Page-Cache-sicher, nur für eingeloggte Kunden.
- **LLM-Antworten** auf Basis von Anthropic Claude.
- **Semantische Produktsuche** mit Voyage-Embeddings und Pinecone.
- **Agentische Commerce-Tools:** Warenkorb, Kasse, Nachbestellung,
  Bestellhistorie, Rechnungen, Sendungsverfolgung, Wunschliste, Adressen,
  Gutscheine und Konto. Jedes Tool ist einzeln schaltbar. Alle Tools sind
  standardmäßig aktiviert; deaktivieren Sie die, die Sie nicht möchten.
- **Konversationsspeicherung**, ein Admin-Konversationsraster und ein
  KPI-Dashboard (Konversionsrate, Klärungsrate, Kosten pro Nachricht, Kosten pro
  Modell).
- **Zweisprachiges Backend** (Englisch und Deutsch) über `i18n`.

## Voraussetzungen

- Magento 2.4.x, PHP 8.1 oder neuer
- Ein **Anthropic**-API-Key (das Gehirn des Chats)
- Ein **Voyage-AI**-API-Key und ein **Pinecone**-API-Key (für die Produktsuche)

Alle drei bieten kostenlose Kontingente oder Guthaben, die zum Testen ausreichen.

## Installation

```bash
composer require zwernemann/magento2-chat
bin/magento module:enable Zwernemann_Chat
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

## Erste Einrichtung

1. **API-Keys besorgen.**
   - Anthropic: https://console.anthropic.com (Bereich *API Keys*)
   - Voyage AI: https://dashboard.voyageai.com (Bereich *API Keys*)
   - Pinecone: https://app.pinecone.io (Bereich *API Keys*; notieren Sie Ihre
     Projektregion, zum Beispiel `us-east-1`)

2. **Keys eintragen** unter *Stores → Configuration → Zwernemann → Chat*:
   - **General → Enable Chat**: Ja
   - **AI Language Model (LLM) → Active Provider**: Anthropic Claude
   - **Anthropic Claude → API Key**
   - **Voyage AI → API Key**
   - **Pinecone → API Key**, **Index Name** (zum Beispiel `magento-products`) und
     **Region** (muss zu Ihrem Pinecone-Projekt passen, zum Beispiel `us-east-1`)
   - **Web Chat Widget → Enable Web Chat Widget**: Ja

3. **Produktindex aufbauen.** Den Pinecone-Index legen Sie nicht von Hand an. Das
   Modul erstellt ihn beim ersten Lauf automatisch (serverless, 1024 Dimensionen
   passend zu `voyage-3`). Führen Sie aus:

   ```bash
   bin/magento cc:index:products
   ```

   Mit `--force` wird alles neu indexiert und Prüfsummen werden ignoriert; mit
   `--store=<code|id>` beschränken Sie den Lauf auf einen Store. Ein nächtlicher
   Cron-Job (`cc_index_products`, 02:00 Uhr) hält den Index danach aktuell.

4. **Storefront öffnen**, als Kunde einloggen: Der Chat-Button erscheint unten in
   der Ecke.

> Kosten: Die Nutzung von Anthropic, Voyage und Pinecone zahlen Sie direkt an
> diese Anbieter. Das Modul protokolliert Tokenverbrauch und Kosten jedes
> Modellaufrufs im Dashboard.

## Navigation im Backend

- **Konfiguration:** Stores → Configuration → **Zwernemann → Chat**
- **Konversationen:** Sales → **Chat → Conversations**. Jede Konversation mit
  Status, Kunde, Kanal und letzter Aktivität. Öffnen Sie eine, um den vollständigen
  Verlauf zu lesen.
- **Dashboard (KPIs):** Sales → **Chat → Dashboard**. Konversionsrate,
  Klärungsrate, Gesamtkosten, Kosten pro Nachricht und Kosten pro Modell für einen
  wählbaren Zeitraum.
- Die Rasterschaltfläche **Re-Index Products** startet eine Katalog-Neuindexierung
  aus dem Backend.

## Vollständige Konfigurationsreferenz

Alle Einstellungen liegen unter *Stores → Configuration → Zwernemann → Chat*.
Felder lassen sich pro Store-View setzen, sofern nicht anders angegeben.

### General
| Einstellung | Bedeutung |
|---|---|
| **Enable Chat** | Hauptschalter für das gesamte Modul. Aus bedeutet kein Widget und keine Pipeline. |

### AI Language Model (LLM)
| Einstellung | Bedeutung |
|---|---|
| **Active Provider** | Welches Modell den Chat betreibt. Das freie Modul bietet Anthropic Claude; das Premium-Modul ergänzt Mistral AI und Google Gemini. |
| **Form of Address** | Ob der Assistent den Kunden förmlich (Sie) oder locker (Du) anspricht. Wirkt auf Anrede und Verabschiedung. |
| **Shop Name (Signature)** | Name, mit dem der Assistent signiert, zum Beispiel *"Ihr Team von Muster GmbH"*. Leer lassen, um den SMTP-Absendernamen zu verwenden. |
| **System Prompt (Default, fixed)** | Nur-Lese-Ansicht des fest hinterlegten Basis-Prompts (Persona und Regeln). Nicht editierbar. |
| **System Prompt Additions** | Freitext, der an den Basis-Prompt angehängt wird. Für shopspezifische Regeln. Wirkt sofort. |
| **History Turns (Main Model)** | Wie viele letzte Gesprächsrunden (eine Nutzer- plus eine Assistentennachricht ergeben einen Turn) dem Hauptmodell als Kontext übergeben werden. Mehr Turns bedeuten mehr Kontext bei höheren Kosten. Standard 4. |
| **History Turns (Account / Order Queries)** | History-Turns für Adress-, Bestell-, Warenkorb- und Kontofragen (keine Produktsuche nötig). Niedriger gehalten, um Tokens zu sparen. Standard 2. |
| **History Messages (Query Builder)** | Wie viele letzte Nachrichten das schnelle Modell sieht, wenn es die Suchanfrage für kurze Rückfragen wie *"ja, zehn davon"* umformuliert. Standard 6. |
| **History Message Max. Characters** | Zeichenobergrenze pro Nachricht in der Modell-History; längere Nachrichten behalten ihre erste und letzte Hälfte. Standard 2000. |

### Anthropic Claude
| Einstellung | Bedeutung |
|---|---|
| **API Key** | Ihr Anthropic-API-Key (verschlüsselt). |
| **Main Model** | Das Modell, das Antworten und Tool-Calls erzeugt. Sonnet ist ein guter Kompromiss aus Qualität und Kosten. |
| **Max Tokens** | Maximale Tokens pro Antwort. Standard 8192. |
| **Fast Model (Query Reformulation)** | Günstigeres Modell, das Suchanfragen aus kurzen Rückfragen umformuliert. Standard Haiku. |

### Voyage AI (Embeddings)
| Einstellung | Bedeutung |
|---|---|
| **API Key** | Ihr Voyage-AI-Key (verschlüsselt). |
| **Model** | Embedding-Modell. Standard `voyage-3` (1024 Dimensionen). |
| **Catalog Language** | Die Sprache, in der Ihr Katalog gespeichert ist. Wenn gesetzt, erzeugt die Begriffsextraktion zusätzlich Synonyme und Übersetzungen in dieser Sprache für fremdsprachige Anfragen. Leer lassen, um die Sprache des Kunden zu behalten, was empfohlen wird, wenn Kunden und Katalog dieselbe Sprache verwenden. |

### Pinecone (Vector DB)
| Einstellung | Bedeutung |
|---|---|
| **API Key** | Ihr Pinecone-Key (verschlüsselt). |
| **Index Name** | Name des Vektorindex, zum Beispiel `magento-products`. Wird beim ersten Indexlauf automatisch angelegt, falls nicht vorhanden. |
| **Region** | Ihre Pinecone-Serverless-Region, zum Beispiel `us-east-1`. Muss zu Ihrem Pinecone-Projekt passen. |
| **Top K Results** | Wie viele Katalogtreffer die Suche pro Anfrage zurückgibt. Standard 25. |

### Product Search / Indexing
| Einstellung | Bedeutung |
|---|---|
| **Excluded Attributes** | Kommagetrennte Attribut-Codes, die nicht an Pinecone gehen (Embed-Text und Metadaten). Tragen Sie hier sensible Felder ein, etwa Einkaufspreise oder interne Notizen. Nach einer Änderung ist ein voller Reindex nötig: `bin/magento cc:index:products --force`. |

### Payment & Shipping
*(Nur relevant, wenn das Checkout-Tool eine Bestellung aufgibt.)*
| Einstellung | Bedeutung |
|---|---|
| **Payment Method Code** | Magento-Zahlartcode für KI-Bestellungen, zum Beispiel `checkmo`, `purchaseorder` oder `free`. Standard `checkmo`. |
| **PO Number Mode** | Für `purchaseorder`: keine, den Kunden fragen oder eine Referenznummer automatisch erzeugen. |
| **Preferred Shipping Method** | Carrier- und Methoden-Code mit Unterstrich verbunden, zum Beispiel `flatrate_flatrate`. Leer lassen, um die erste verfügbare Methode zu verwenden. |

### Magento REST API
| Einstellung | Bedeutung |
|---|---|
| **Base URL** | Leer lassen, um diese Instanz zu verwenden. Nur für ein getrenntes oder Headless-Setup setzen. |
| **Admin Integration Token** | Verschlüsselter Token für diese entfernte Instanz. |

### Web Chat Widget
| Einstellung | Bedeutung |
|---|---|
| **Enable Web Chat Widget** | Zeigt das Storefront-Widget (nur für eingeloggte Kunden). |
| **Widget Title** | Text in der Chat-Kopfzeile. |
| **Chat Button Label** | Text auf dem schwebenden Button. |
| **Welcome Message** | Erste Nachricht beim Öffnen des Chats. |
| **Primary Color** | Hex-Farbcode für Kopfzeile, Button und Akzente, zum Beispiel `#1a73e8`. |
| **LLM Debug Output (WebChat)** | Zeigt die rohe Modellanfrage und -antwort als Debug-Block im Chatfenster. Im Produktivbetrieb ausschalten. |

### Allowed Actions (Tool-Berechtigungen)
Jedes Tool, das der Assistent nutzen darf, wird hier geschaltet. Alle Aktionen sind
standardmäßig aktiviert, damit der Assistent sofort voll handlungsfähig ist. Zum
Einschränken deaktivieren Sie einzelne Tools; ein deaktiviertes Tool wird dem
Modell gar nicht erst angeboten. Beachten Sie, dass schreibende Tools (Warenkorb,
Kasse, Adresse, Konto und so weiter) echte Aktionen im Namen des Kunden ausführen.
Prüfen Sie diese vor dem Livegang.

| Tool (Code) | Typ | Funktion |
|---|---|---|
| `search_products_by_filter` | lesend | Strukturierte Katalog-Filtersuche |
| `get_order_history` | lesend | Frühere Bestellungen des Kunden auflisten |
| `get_order_detail` | lesend | Details einer einzelnen Bestellung |
| `get_shipment_tracking` | lesend | Trackingnummern und Status |
| `get_invoice` | lesend | Rechnung abrufen |
| `get_shipping_addresses` | lesend | Gespeicherte Lieferadressen auflisten |
| `get_wishlist` | lesend | Wunschliste anzeigen |
| `get_account_info` | lesend | Kontodaten anzeigen |
| `cart_add_item` | schreibend | Artikel in den Warenkorb legen |
| `cart_update_item` | schreibend | Warenkorbmenge ändern |
| `cart_remove_item` | schreibend | Warenkorbposition entfernen |
| `cart_checkout` | schreibend | Bestellung aufgeben und auschecken |
| `reorder_from_history` | schreibend | Eine frühere Bestellung nachbestellen |
| `add_shipping_address` | schreibend | Neue Lieferadresse hinzufügen |
| `set_order_shipping_address` | schreibend | Lieferadresse einer Bestellung ändern |
| `apply_coupon_code` / `remove_coupon_code` | schreibend | Gutscheine verwalten |
| `wishlist_add_item` / `wishlist_remove_item` / `wishlist_move_to_cart` | schreibend | Wunschliste ändern |
| `update_account_info` | schreibend | Kontodaten ändern |
| `toggle_newsletter` | schreibend | Newsletter an- oder abmelden |
| `set_stock_notification` | schreibend | Benachrichtigung bei Wiederverfügbarkeit |

## CLI-Befehle

| Befehl | Zweck |
|---|---|
| `bin/magento cc:index:products` | Alle Produkte in Pinecone indexieren. `--force` ignoriert Prüfsummen, `--store=<code\|id>` beschränkt den Lauf auf einen Store. |

Der Cron-Job `cc_index_products` indexiert geänderte Produkte nächtlich um 02:00 Uhr neu.

## Premium-Erweiterung

Das kommerzielle Modul **`zwernemann/module-conversional-commerce`** baut auf
dieser Basis auf und ergänzt:

- **Weitere LLM-Provider:** Mistral AI (DSGVO-freundlich, EU-Rechenzentren) und
  Google Gemini Flash, wählbar im selben Dropdown **Active Provider**.
- **E-Mail-Kanal:** Kunden bestellen per E-Mail. Ein IMAP-Poller nimmt Nachrichten
  entgegen, inklusive Excel-, PDF- und Word-Anhängen, und antwortet per SMTP.
- **WhatsApp-Kanal:** eingehende REST-API plus Connector.
- **Menschliche Eskalation:** Auslöser für Konfidenz, Schlüsselwörter und
  Bestellwert pausieren die KI, benachrichtigen einen Admin und verlangen eine
  Freigabe, bevor das Gespräch weitergeht.
- **DSGVO und Datenlöschung:** Anonymisierung und Hard-Delete-Werkzeuge sowie ein
  Self-Service-Löschendpunkt für Kunden.

Es klinkt sich über DI-Erweiterungsnahtstellen ein
(`EscalationHandlerInterface`, `ErrorNotifierInterface`, die Kanal-Map des
MessageProcessor und der LLM-Provider-Pool), sodass das freie Modul auch allein
läuft.

## Architekturhinweis

Das freie Modul besitzt die Nachrichten-Pipeline (`MessageProcessor`) sowie die
Speicher-, LLM-, RAG- und Tool-Schichten. Premium-Verhalten wird über klar
definierte Nahtstellen eingespielt statt fest verdrahtet. Deshalb berührt das
Installieren oder Entfernen des Premium-Moduls den Code dieses Moduls nie.

## Versionsverlauf

### 1.0.0

- Erstveröffentlichung des kostenlosen, quelloffenen KI-Chat-Moduls, herausgelöst aus der Zwernemann-ConversationalCommerce-Engine.
- WebChat-Widget, semantische Produktsuche (RAG), agentische Commerce-Tools, Konversationsspeicherung und ein KPI-Dashboard.
- Anthropic Claude als eingebauter Provider; Mistral und Gemini über das Premium-Modul verfügbar.
- Getestet mit Magento 2.4.6 bis 2.4.8-p1.

## Kontakt & Support

**Zwernemann Medienentwicklung**\
Martin Zwernemann\
79730 Murg, Deutschland

[Zur Webseite](https://www.zwernemann.de/ki-chat-fur-magento-2-kostenlos-open-source/)

Bei Fragen, Problemen oder Ideen für neue Funktionen melden Sie sich gerne.

## Lizenz

OSL-3.0. Siehe [`LICENSE`](LICENSE).
