# BexioService Einbindungsbeispiele

## 1. Grundlegende Einbindung

```php
<?php
declare(strict_types=1);

/**
 * Beispiel: BexioService einbinden
 */

// Config-Konstanten definieren
define('BEXIO_API_TOKEN', 'DEIN_BEXIO_PERSONAL_ACCESS_TOKEN');
define('BEXIO_BASE_URL', 'https://api.bexio.com/');

define('BEXIO_USER_ID', 1);
define('BEXIO_CURRENCY_ID', 1);       // CHF
define('BEXIO_PAYMENT_TYPE_ID', 4);   // Zahlungsart
define('BEXIO_ACCOUNT_ID', 172);      // Ertragskonto
define('BEXIO_TAX_ID', 14);           // MWST / Steuer-ID
define('BEXIO_BANK_ACCOUNT_ID', 1);   // Bankkonto für Zahlungseingänge

// Service einbinden
require_once __DIR__ . '/BexioService.php';

// Service initialisieren
$bexio = new BexioService();
```

---

## 2. Kontakt suchen oder erstellen

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$buyer = [
    'first_name' => 'Max',
    'last_name'  => 'Muster',
    'email'      => 'max.muster@example.ch',
    'phone'      => '+41 79 123 45 67',
    'company'    => 'Muster GmbH',
    'address'    => 'Bahnhofstrasse 10',
    'zip'        => '8200',
    'city'       => 'Schaffhausen',
    'country'    => 'CH',
    'website'    => 'https://www.example.ch',

    // Optional: Kontaktgruppen in Bexio
    // Beispiel: [7, 8] für Sponsor + SaaS
    '_contact_group_ids' => [7, 8],
];

$contactId = $bexio->searchOrCreateContact($buyer);

if ($contactId > 0) {
    echo "Kontakt-ID: " . $contactId;
} else {
    echo "Kontakt konnte nicht erstellt oder gefunden werden.";
}
```

---

## 3. Einzelne Rechnung erstellen

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$contactId      = 123;
$localInvoiceNr = 'FS-2026-0001';
$title          = 'Sponsoring FieldShare';
$amount         = 300.00;
$description    = 'Sponsoringfläche Saison 2026';
$dueDate        = date('Y-m-d', strtotime('+30 days'));

$invoice = $bexio->createInvoice(
    $contactId,
    $localInvoiceNr,
    $title,
    $amount,
    $description,
    $dueDate
);

if ($invoice !== null) {
    echo "Rechnung erstellt: " . $invoice['document_nr'];
} else {
    echo "Rechnung konnte nicht erstellt werden.";
}
```

---

## 4. Kompletter Ablauf: Kontakt + Rechnung + Ausgabe

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$buyer = [
    'first_name' => 'Max',
    'last_name'  => 'Muster',
    'email'      => 'max.muster@example.ch',
    'phone'      => '+41 79 123 45 67',
    'company'    => 'Muster GmbH',
    'address'    => 'Bahnhofstrasse 10',
    'zip'        => '8200',
    'city'       => 'Schaffhausen',
    'country'    => 'CH',
    'website'    => 'https://www.example.ch',
    '_contact_group_ids' => [7, 8],
];

$result = $bexio->createFullInvoice(
    $buyer,
    'FS-2026-0001',
    'Sponsoring FieldShare',
    300.00,
    'Sponsoringfläche Saison 2026',
    date('Y-m-d', strtotime('+30 days'))
);

if ($result['ok'] === true) {
    echo "Rechnung erfolgreich erstellt.\n";
    echo "Bexio Kontakt-ID: " . $result['bexio_contact_id'] . "\n";
    echo "Bexio Rechnungs-ID: " . $result['bexio_invoice_id'] . "\n";
    echo "Bexio Rechnungsnummer: " . $result['bexio_invoice_nr'] . "\n";
} else {
    echo "Fehler beim Erstellen der Rechnung:\n";
    echo $result['error'] . "\n";
}
```

---

## 5. Rechnung mit mehreren Positionen erstellen

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$buyer = [
    'first_name' => 'Max',
    'last_name'  => 'Muster',
    'email'      => 'max.muster@example.ch',
    'company'    => 'Muster GmbH',
    'address'    => 'Bahnhofstrasse 10',
    'zip'        => '8200',
    'city'       => 'Schaffhausen',
    'country'    => 'CH',
];

$positions = [
    [
        'text'       => 'Sponsoringfläche A1',
        'unit_price' => 100.00,
    ],
    [
        'text'       => 'Sponsoringfläche A2',
        'unit_price' => 100.00,
    ],
    [
        'text'       => 'Sponsoringfläche B1',
        'unit_price' => 100.00,
    ],
];

$result = $bexio->createFullInvoiceWithPositions(
    $buyer,
    'FS-2026-0002',
    'Sponsoring FieldShare',
    $positions,
    date('Y-m-d', strtotime('+30 days'))
);

if ($result['ok'] === true) {
    echo "Rechnung mit mehreren Positionen erstellt.\n";
    echo "Bexio Rechnungsnummer: " . $result['bexio_invoice_nr'];
} else {
    echo "Fehler: " . $result['error'];
}
```

---

## 6. Rechnung ausstellen

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$bexioInvoiceId = 456;

$issued = $bexio->issueInvoice($bexioInvoiceId);

if ($issued) {
    echo "Rechnung wurde ausgestellt.";
} else {
    echo "Rechnung konnte nicht ausgestellt werden.";
}
```

---

## 7. Rechnung per E-Mail senden

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$bexioInvoiceId = 456;
$email          = 'max.muster@example.ch';
$name           = 'Max Muster';

$sent = $bexio->sendInvoice(
    $bexioInvoiceId,
    $email,
    $name
);

if ($sent) {
    echo "Rechnung wurde per E-Mail gesendet.";
} else {
    echo "Rechnung konnte nicht gesendet werden.";
}
```

---

## 8. Rechnungs-PDF abrufen und speichern

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$bexioInvoiceId = 456;

$pdf = $bexio->getInvoicePdf($bexioInvoiceId);

if ($pdf !== null && !empty($pdf['content'])) {
    $filename = $pdf['name'] ?? 'rechnung.pdf';
    $content  = base64_decode($pdf['content']);

    file_put_contents(__DIR__ . '/' . $filename, $content);

    echo "PDF gespeichert: " . $filename;
} else {
    echo "PDF konnte nicht abgerufen werden.";
}
```

---

## 9. Zahlung auf Rechnung buchen

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$bexioInvoiceId = 456;
$amount         = 300.00;
$date           = date('Y-m-d');

$paid = $bexio->bookPayment(
    $bexioInvoiceId,
    $amount,
    $date
);

if ($paid) {
    echo "Zahlung wurde gebucht.";
} else {
    echo "Zahlung konnte nicht gebucht werden.";
}
```

---

## 10. Rechnung löschen oder stornieren

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$bexioInvoiceId = 456;

$status = $bexio->cancelAndDeleteInvoice($bexioInvoiceId);

if ($status === 'deleted') {
    echo "Rechnung wurde gelöscht.";
} elseif ($status === 'cancelled') {
    echo "Rechnung wurde storniert, aber nicht gelöscht.";
} elseif ($status === 'cannot_delete') {
    echo "Rechnung kann nicht gelöscht werden.";
} else {
    echo "Fehler beim Löschen oder Stornieren.";
}
```

---

## 11. Kontakt anonymisieren und archivieren

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$contactId = 123;

$status = $bexio->anonymizeAndArchiveContact($contactId);

if ($status === 'anonymized') {
    echo "Kontakt wurde anonymisiert und archiviert.";
} else {
    echo "Kontakt konnte nicht anonymisiert werden.";
}
```

---

## 12. Bankkonten abrufen

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$accounts = $bexio->getBankAccounts();

foreach ($accounts as $account) {
    echo $account['name'] . "\n";
    echo $account['uuid'] . "\n";
    echo $account['iban'] . "\n\n";
}
```

---

## 13. Bank-Zahlungsauftrag erstellen

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

try {
    $payment = $bexio->createBankPayment([
        'account_uuid'     => 'BANKKONTO-UUID-AUS-BEXIO',
        'recipient_iban'   => 'CH9300762011623852957',
        'recipient_name'   => 'Verein Beispiel',
        'street_name'      => 'Sportplatzweg',
        'house_number'     => '1',
        'zip'              => '8200',
        'city'             => 'Schaffhausen',
        'country_code'     => 'CH',
        'amount'           => 250.00,
        'currency'         => 'CHF',
        'execution_date'   => date('Y-m-d', strtotime('+1 day')),
        'message'          => 'Auszahlung Sponsoring FieldShare',
        'is_salary'        => false,
    ]);

    echo "Zahlungsauftrag erstellt: " . $payment['uuid'];
} catch (RuntimeException $e) {
    echo "Fehler beim Zahlungsauftrag: " . $e->getMessage();
}
```

---

## 14. Bank-Zahlungsauftrag abrufen

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/BexioService.php';

$bexio = new BexioService();

$uuid = 'ZAHLUNGS-UUID-AUS-BEXIO';

$payment = $bexio->getBankPayment($uuid);

if ($payment !== null) {
    echo "Zahlung gefunden:\n";
    echo "UUID: " . $payment['uuid'] . "\n";
    echo "Betrag: " . $payment['amount'] . "\n";
    echo "Status: " . ($payment['status'] ?? 'unbekannt') . "\n";
} else {
    echo "Zahlung konnte nicht gefunden werden.";
}
```
