# PHPBexioAPI

Bexio REST API v2 integration.

- Authentication: Personal Access Token (Bearer).
- Required scopes: contact_show, contact_edit, kb_invoice_show, kb_invoice_edit

# Methoden und Config-Variablen

## Klasse

`BexioService`

Integration für die Bexio REST API v2 und teilweise Banking API v4.

---

## Config-Variablen / Konstanten

Diese Konstanten werden im Code verwendet und können in der Projekt-Config definiert werden.

| Konstante | Zweck | Fallback |
|---|---|---|
| `BEXIO_API_TOKEN` | Personal Access Token für die Bexio API | `''` |
| `BEXIO_BASE_URL` | Basis-URL der Bexio API | `https://api.bexio.com/` |
| `BEXIO_USER_ID` | Bexio Benutzer-ID für Kontakte, Rechnungen und Ausgaben | `1` oder aus bestehendem Kontakt |
| `BEXIO_CURRENCY_ID` | Währungs-ID, z. B. CHF | `1` |
| `BEXIO_PAYMENT_TYPE_ID` | Zahlungsart-ID für Rechnungen | `4` |
| `BEXIO_ACCOUNT_ID` | Buchhaltungskonto für Rechnungspositionen | `172` |
| `BEXIO_TAX_ID` | Steuer-ID für Rechnungspositionen | `14` |
| `BEXIO_BANK_ACCOUNT_ID` | Bankkonto-ID für Zahlungseingänge auf Rechnungen | `1` |

---

## Private Eigenschaften

| Property | Typ | Zweck |
|---|---|---|
| `$apiToken` | `string` | API Token für Bearer Authentifizierung |
| `$baseUrl` | `string` | Basis-URL für API Requests |

---

## Konstruktor

### `__construct(?string $apiToken = null)`

Initialisiert den API Token und die Base URL.

| Parameter | Typ | Beschreibung |
|---|---|---|
| `$apiToken` | `?string` | Optionaler API Token. Wenn leer, wird `BEXIO_API_TOKEN` verwendet. |

---

## Private Methoden

### `request(string $method, string $endpoint, array $data = []): array`

Zentrale HTTP-Methode für alle Bexio API Requests.

| Parameter | Typ | Beschreibung |
|---|---|---|
| `$method` | `string` | HTTP-Methode, z. B. `GET`, `POST`, `PATCH`, `DELETE` |
| `$endpoint` | `string` | API Endpoint relativ zur Base URL |
| `$data` | `array` | Request Body für POST/PATCH |

**Rückgabe:**  
Array mit API-Antwort oder Fehlerdaten wie `_error`, `_http_code`, `_raw`.

---

### `buildContactPayload(array $buyer): array`

Erstellt das Bexio Contact Payload aus einem Buyer-Array.

| Parameter | Typ | Beschreibung |
|---|---|---|
| `$buyer` | `array` | Käuferdaten wie Name, Firma, E-Mail, Adresse usw. |

**Unterstützte Buyer-Felder:**

| Feld | Beschreibung |
|---|---|
| `first_name` | Vorname |
| `last_name` | Nachname |
| `email` | E-Mail-Adresse |
| `phone` | Telefonnummer |
| `company` | Firmenname |
| `address` | Adresse |
| `zip` | PLZ |
| `city` | Ort |
| `country` | Ländercode, z. B. `CH`, `DE`, `AT`, `LI` |
| `website` | Webseite |
| `_contact_group_ids` | Optionale Bexio Kontaktgruppen-IDs |

---

### `createInvoiceMultiPosition(int $contactId, string $localInvoiceNr, string $title, array $positions, string $dueDate): ?array`

Erstellt eine Bexio Rechnung mit mehreren Positionen.

| Parameter | Typ | Beschreibung |
|---|---|---|
| `$contactId` | `int` | Bexio Kontakt-ID |
| `$localInvoiceNr` | `string` | Interne Rechnungsnummer |
| `$title` | `string` | Rechnungstitel |
| `$positions` | `array` | Rechnungspositionen |
| `$dueDate` | `string` | Fälligkeitsdatum im Format `Y-m-d` |

**Positionsformat:**

```php
[
    [
        'text' => 'Beschreibung',
        'unit_price' => 100.00
    ]
]
