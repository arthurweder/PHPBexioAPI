# Bexio Zuordnung für FieldShare Buchungen und Clubs

## Kontakt-Kategorien in Bexio

Parameter: `contact_group_ids`

| ID | Kategorie |
|---:|---|
| `6` | Clubs |
| `7` | Sponsor |
| `8` | SaaS |

---

## Kontakt-Typen in Bexio

Parameter: `contact_type_id`

| ID | Typ |
|---:|---|
| `1` | Firma / Organisation |
| `2` | Privatperson |

---

# 1. Buchung / Sponsoring

Wenn eine Buchung ausgelöst wird, z. B. aus dem Sponsoring- oder Zahlungsprozess, soll der Kontakt in Bexio mit diesen Kategorien erfasst werden:

```php
'contact_group_ids' => [7, 8]
```

Bedeutung:

| Kategorie | ID |
|---|---:|
| Sponsor | `7` |
| SaaS | `8` |

## Kontakt-Typ bei Buchung

Wenn `buyer_company` ausgefüllt ist:

```php
'contact_type_id' => 1
```

Das bedeutet:

```text
Firma / Organisation
```

Wenn `buyer_company` nicht ausgefüllt ist:

```php
'contact_type_id' => 2
```

Das bedeutet:

```text
Privatperson
```

## Beispiel Buyer-Mapping für eine Sponsoring-Buchung

```php
$buyer = [
    'first_name' => $booking['buyer_first_name'] ?? '',
    'last_name'  => $booking['buyer_last_name'] ?? '',
    'email'      => $booking['buyer_email'] ?? '',
    'phone'      => $booking['buyer_phone'] ?? '',

    // Wichtig:
    // buyer_company aus dem Formular wird auf company gemappt,
    // weil BexioService intern das Feld company erwartet.
    'company'    => $booking['buyer_company'] ?? '',

    'address'    => $booking['buyer_address'] ?? '',
    'zip'        => $booking['buyer_zip'] ?? '',
    'city'       => $booking['buyer_city'] ?? '',
    'country'    => $booking['buyer_country'] ?? 'CH',
    'website'    => $booking['buyer_website'] ?? '',

    // Bexio Kategorien: Sponsor + SaaS
    '_contact_group_ids' => [7, 8],
];
```

Der `BexioService` setzt daraus automatisch:

```php
contact_type_id = 1
```

wenn `company` ausgefüllt ist.

Oder:

```php
contact_type_id = 2
```

wenn `company` leer ist.

---

# 2. Club-Freischaltung über `/club/create`

Wenn ein Club freigeschaltet oder erstellt wird, soll der Kontakt in Bexio mit diesen Kategorien erfasst werden:

```php
'contact_group_ids' => [6, 8]
```

Bedeutung:

| Kategorie | ID |
|---|---:|
| Clubs | `6` |
| SaaS | `8` |

## Kontakt-Typ bei Club

Ein Club ist immer eine Firma / Organisation.

```php
'contact_type_id' => 1
```

## Beispiel Buyer-Mapping für Club-Erstellung

```php
$buyer = [
    'first_name' => $club['contact_first_name'] ?? '',
    'last_name'  => $club['contact_last_name'] ?? '',
    'email'      => $club['email'] ?? '',
    'phone'      => $club['phone'] ?? '',

    // Wichtig:
    // Clubname muss als company gesetzt werden,
    // damit Bexio contact_type_id = 1 verwendet.
    'company'    => $club['name'] ?? '',

    'address'    => $club['address'] ?? '',
    'zip'        => $club['zip'] ?? '',
    'city'       => $club['city'] ?? '',
    'country'    => $club['country'] ?? 'CH',
    'website'    => $club['website'] ?? '',

    // Bexio Kategorien: Clubs + SaaS
    '_contact_group_ids' => [6, 8],
];
```

Danach kann der Kontakt so erstellt oder aktualisiert werden:

```php
$bexio = new BexioService();

$bexioContactId = $bexio->searchOrCreateContact($buyer);

if ($bexioContactId > 0) {
    echo "Bexio Kontakt erstellt oder aktualisiert: " . $bexioContactId;
} else {
    echo "Bexio Kontakt konnte nicht erstellt werden.";
}
```

---

# 3. Adresse, PLZ, Ort und Firma auf der Bexio Rechnung

Damit Firma, Adresse, PLZ und Ort korrekt auf der Bexio Rechnung erscheinen, müssen diese Felder im Buyer-Array korrekt gesetzt sein:

```php
$buyer = [
    'company' => 'Muster GmbH',
    'address' => 'Bahnhofstrasse 10',
    'zip'     => '8200',
    'city'    => 'Schaffhausen',
    'country' => 'CH',
];
```

Der `BexioService` macht daraus für Bexio:

```php
[
    'name_1'       => 'Muster GmbH',
    'street_name'  => 'Bahnhofstrasse',
    'house_number' => '10',
    'postcode'     => '8200',
    'city'         => 'Schaffhausen',
    'country_id'   => 1,
]
```

Wenn keine Firma gesetzt ist, wird eine Privatperson erstellt:

```php
$buyer = [
    'first_name' => 'Max',
    'last_name'  => 'Muster',
    'company'    => '',
    'address'    => 'Bahnhofstrasse 10',
    'zip'        => '8200',
    'city'       => 'Schaffhausen',
    'country'    => 'CH',
];
```

Daraus wird:

```php
[
    'contact_type_id' => 2,
    'name_1'          => 'Muster',
    'name_2'          => 'Max',
    'street_name'     => 'Bahnhofstrasse',
    'house_number'    => '10',
    'postcode'        => '8200',
    'city'            => 'Schaffhausen',
    'country_id'      => 1,
]
```

---

# 4. Beispiel: Komplette Sponsoring-Buchung mit Bexio Rechnung

```php
$bexio = new BexioService();

$buyer = [
    'first_name' => $booking['buyer_first_name'] ?? '',
    'last_name'  => $booking['buyer_last_name'] ?? '',
    'email'      => $booking['buyer_email'] ?? '',
    'phone'      => $booking['buyer_phone'] ?? '',
    'company'    => $booking['buyer_company'] ?? '',
    'address'    => $booking['buyer_address'] ?? '',
    'zip'        => $booking['buyer_zip'] ?? '',
    'city'       => $booking['buyer_city'] ?? '',
    'country'    => $booking['buyer_country'] ?? 'CH',
    'website'    => $booking['buyer_website'] ?? '',

    // Sponsor + SaaS
    '_contact_group_ids' => [7, 8],
];

$result = $bexio->createFullInvoice(
    $buyer,
    $booking['invoice_nr'],
    'Sponsoring FieldShare',
    (float)$booking['amount'],
    'Sponsoringfläche ' . $booking['field_label'],
    date('Y-m-d', strtotime('+30 days'))
);

if ($result['ok'] === true) {
    echo "Bexio Rechnung erstellt: " . $result['bexio_invoice_nr'];
} else {
    echo "Fehler: " . $result['error'];
}
```

---

# 5. Beispiel: Club freischalten und Bexio Kontakt erstellen

```php
$bexio = new BexioService();

$buyer = [
    'first_name' => $club['contact_first_name'] ?? '',
    'last_name'  => $club['contact_last_name'] ?? '',
    'email'      => $club['email'] ?? '',
    'phone'      => $club['phone'] ?? '',
    'company'    => $club['name'] ?? '',
    'address'    => $club['address'] ?? '',
    'zip'        => $club['zip'] ?? '',
    'city'       => $club['city'] ?? '',
    'country'    => $club['country'] ?? 'CH',
    'website'    => $club['website'] ?? '',

    // Clubs + SaaS
    '_contact_group_ids' => [6, 8],
];

$bexioContactId = $bexio->searchOrCreateContact($buyer);

if ($bexioContactId > 0) {
    echo "Bexio Club-Kontakt erstellt oder aktualisiert: " . $bexioContactId;
} else {
    echo "Bexio Club-Kontakt konnte nicht erstellt werden.";
}
```

---

# 6. Wichtig für den bestehenden BexioService

Der bestehende `BexioService` erwartet für Kontaktgruppen nicht direkt `contact_group_ids`, sondern intern:

```php
'_contact_group_ids' => [7, 8]
```

Die Methode `buildContactPayload()` wandelt das dann automatisch um in:

```php
'contact_group_ids' => [7, 8]
```

Darum bei der Übergabe an `createContact()`, `updateContact()`, `searchOrCreateContact()`, `createFullInvoice()` oder `createFullInvoiceWithPositions()` immer dieses Feld verwenden:

```php
'_contact_group_ids' => [7, 8]
```

oder für Clubs:

```php
'_contact_group_ids' => [6, 8]
```
