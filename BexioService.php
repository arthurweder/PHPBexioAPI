<?php
declare(strict_types=1);

/**
 * Bexio REST API v2 integration.
 *
 * Authentication: Personal Access Token (Bearer).
 * Required scopes: contact_show, contact_edit, kb_invoice_show, kb_invoice_edit
 *
 * Docs: https://docs.bexio.com/
 */
class BexioService
{
    private string $apiToken;
    private string $baseUrl;

    public function __construct(?string $apiToken = null)
    {
        $this->apiToken = $apiToken ?? (defined('BEXIO_API_TOKEN') ? BEXIO_API_TOKEN : '');
        $this->baseUrl  = rtrim(defined('BEXIO_BASE_URL') ? BEXIO_BASE_URL : 'https://api.bexio.com/', '/') . '/';
    }

    // ─── Private: HTTP ────────────────────────────────────────────────────

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');
        $ch  = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $method = strtoupper($method);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) {
            error_log('[BexioService] cURL error: ' . $curlErr);
            return ['_error' => $curlErr, '_http_code' => 0];
        }

        // 204 No Content or any 2xx with empty body → success
        if ($httpCode >= 200 && $httpCode < 300 && ($response === '' || $response === false)) {
            return ['_http_code' => $httpCode];
        }

        $decoded = json_decode((string)$response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Non-JSON body on a success response (e.g. plain "true") → treat as success
            if ($httpCode >= 200 && $httpCode < 300) {
                return ['_http_code' => $httpCode];
            }
            error_log('[BexioService] JSON decode error on ' . $method . ' ' . $endpoint . ': ' . $response);
            return ['_error' => 'Invalid JSON', '_http_code' => $httpCode, '_raw' => $response];
        }

        if ($httpCode >= 400) {
            $errMsg = is_array($decoded) ? ($decoded['message'] ?? json_encode($decoded)) : (string)$response;
            error_log('[BexioService] HTTP ' . $httpCode . ' on ' . $method . ' ' . $endpoint . ': ' . $errMsg);
            $decoded['_error']     = $errMsg;
            $decoded['_http_code'] = $httpCode;
        }

        return is_array($decoded) ? $decoded : ['_raw' => $decoded];
    }

    // ─── Contacts ─────────────────────────────────────────────────────────

    /**
     * List all contacts from Bexio (paginated, max 2000).
     */
    public function listContacts(int $offset = 0, int $limit = 500): array
    {
        $result = $this->request('GET', "2.0/contact?offset={$offset}&limit={$limit}");
        if (isset($result['_error']) || !is_array($result)) {
            return [];
        }
        // Numerically indexed = list of contacts
        return isset($result[0]) || empty($result) ? $result : [];
    }

    /**
     * Delete a single contact from Bexio.
     * Returns true on success, false otherwise.
     */
    public function deleteContact(int $contactId): bool
    {
        $result = $this->request('DELETE', "2.0/contact/{$contactId}");
        return !isset($result['_error']);
    }

    /**
     * Anonymize a contact by overwriting personal data with placeholder values,
     * then archive it. Used instead of DELETE when the contact is linked to invoices.
     * Returns 'anonymized', or 'error'.
     */
    public function anonymizeAndArchiveContact(int $contactId): string
    {
        // Fetch existing contact to preserve required fields (type, user_id, owner_id)
        $existing = $this->request('GET', "2.0/contact/{$contactId}");
        if (isset($existing['_error'])) {
            return 'error';
        }

        $userId = defined('BEXIO_USER_ID') ? (int)BEXIO_USER_ID : (int)($existing['user_id'] ?? 1);

        $anonymized = [
            'contact_type_id' => (int)($existing['contact_type_id'] ?? 1),
            'name_1'          => 'Löschen',
            'name_2'          => 'Löschen',
            'mail'            => '',
            'street_name'     => 'Löschen',
            'house_number'    => '',
            'postcode'        => '',
            'city'            => 'Löschen',
            'phone_fixed'     => '',
            'phone_mobile'    => '',
            'remarks'         => '',
            'user_id'         => $userId,
            'owner_id'        => $userId,
        ];

        $update = $this->request('POST', "2.0/contact/{$contactId}", $anonymized);
        if (isset($update['_error'])) {
            error_log('[BexioService::anonymizeAndArchiveContact] Update failed for contact ' . $contactId . ': ' . json_encode($update));
            return 'error';
        }

        // Archive the contact
        $this->request('POST', "2.0/contact/{$contactId}/archive");

        return 'anonymized';
    }

    /**
     * Search for a contact by email. Returns the first match or null.
     */
    public function searchContactByEmail(string $email): ?array
    {
        $result = $this->request('POST', '2.0/contact/search', [
            ['field' => 'mail', 'value' => $email, 'criteria' => '='],
        ]);

        if (isset($result['_error'])) {
            return null;
        }

        if (!empty($result) && is_array($result) && isset($result[0]['id'])) {
            return $result[0];
        }

        return null;
    }

    /**
     * Create a new contact in Bexio.
     *
    /**
     * Build the Bexio contact payload from a buyer array.
     * Shared by createContact() and updateContact().
     */
    private function buildContactPayload(array $buyer): array
    {
        $userId = defined('BEXIO_USER_ID') ? (int)BEXIO_USER_ID : 1;

        $isCompany = !empty(trim($buyer['company'] ?? ''));

        if ($isCompany) {
            // Firma: contact_type_id = 1 (confirmed via Bexio API: id=1 name=Firma)
            $contactPerson = trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? ''));
            $data = [
                'contact_type_id' => 1,
                'name_1'          => trim($buyer['company']),
                'name_2'          => $contactPerson ?: null,
                'mail'            => $buyer['email'] ?? '',
                'user_id'         => $userId,
                'owner_id'        => $userId,
            ];
        } else {
            // Privat: contact_type_id = 2 (confirmed via Bexio API: id=2 name=Privat)
            $data = [
                'contact_type_id' => 2,
                'name_1'          => trim($buyer['last_name']  ?? ''),
                'name_2'          => trim($buyer['first_name'] ?? '') ?: null,
                'mail'            => $buyer['email']      ?? '',
                'user_id'         => $userId,
                'owner_id'        => $userId,
            ];
        }

        // Fallback: use email prefix if name_1 is empty
        if (empty($data['name_1'])) {
            $data['name_1'] = explode('@', $buyer['email'] ?? 'Unbekannt')[0];
        }

        if (!empty($buyer['phone'])) {
            // Companies get phone_fixed; private persons get phone_mobile
            if ($isCompany) {
                $data['phone_fixed'] = $buyer['phone'];
            } else {
                $data['phone_mobile'] = $buyer['phone'];
            }
        }

        if (!empty($buyer['address'])) {
            $addr = trim($buyer['address']);
            if (preg_match('/^(.*?)\s+(\d+\w*)$/', $addr, $m)) {
                $data['street_name']  = $m[1];
                $data['house_number'] = $m[2];
            } else {
                $data['street_name'] = $addr;
            }
        }

        if (!empty($buyer['zip']))  { $data['postcode'] = $buyer['zip']; }
        if (!empty($buyer['city'])) { $data['city']     = $buyer['city']; }

        // Always include url (even empty string) so updates can clear a stale value
        $data['url'] = trim($buyer['website'] ?? '');

        $countryCode = strtoupper($buyer['country'] ?? 'CH');
        $data['country_id'] = match ($countryCode) {
            'CH' => 1,
            'DE' => 3,
            'AT' => 2,
            'LI' => 52,
            default => 1,
        };

        // Optional contact group IDs (e.g. [6,8] for clubs, [7,8] for sponsors)
        if (!empty($buyer['_contact_group_ids']) && is_array($buyer['_contact_group_ids'])) {
            $data['contact_group_ids'] = array_values(array_map('intval', $buyer['_contact_group_ids']));
        }

        return $data;
    }

    /**
     * Create a new contact in Bexio.
     *
     * @param array $buyer {first_name, last_name, email, phone, company, address, zip, city, country}
     * @return array|null  Created contact data or null on error.
     */
    public function createContact(array $buyer): ?array
    {
        $data   = $this->buildContactPayload($buyer);
        $result = $this->request('POST', '2.0/contact', $data);

        if (isset($result['_error']) || empty($result['id'])) {
            error_log('[BexioService::createContact] Failed. Response: ' . json_encode($result));
            return null;
        }

        return $result;
    }

    /**
     * Update an existing Bexio contact with current buyer data.
     * Note: Bexio v2 API uses POST (not PATCH) for contact updates.
     */
    public function updateContact(int $contactId, array $buyer): bool
    {
        $data   = $this->buildContactPayload($buyer);
        $result = $this->request('POST', "2.0/contact/{$contactId}", $data);

        // Success: got back the updated contact (has id)
        if (!isset($result['_error']) && !empty($result['id'])) {
            return true;
        }

        error_log('[BexioService::updateContact] Failed for #' . $contactId . '. Response: ' . json_encode($result));
        return false;
    }

    /**
     * Search for an existing contact by email; create or update as needed.
     *
     * @return int  Bexio contact ID, or 0 on failure.
     */
    public function searchOrCreateContact(array $buyer): int
    {
        $email = trim($buyer['email'] ?? '');
        if ($email === '') {
            return 0;
        }

        $existing = $this->searchContactByEmail($email);
        if ($existing !== null) {
            $contactId  = (int)$existing['id'];
            $isCompany  = !empty(trim($buyer['company'] ?? ''));
            $wasCompany = (int)($existing['contact_type_id'] ?? 1) === 2;

            // Always update so name, address, and type stay current
            $this->updateContact($contactId, $buyer);

            return $contactId;
        }

        $created = $this->createContact($buyer);
        return $created ? (int)$created['id'] : 0;
    }


    // ─── Invoices ─────────────────────────────────────────────────────────

    /**
     * Create a new invoice in Bexio.
     *
     * @param int    $contactId       Bexio contact ID
     * @param string $localInvoiceNr  Our internal invoice number (stored in Bexio as reference)
     * @param string $title           Invoice title / subject
     * @param float  $amount          Total amount (CHF)
     * @param string $description     Line item description
     * @param string $dueDate         Due date (Y-m-d)
     * @return array|null  Bexio invoice data or null on error.
     */
    public function createInvoice(
        int    $contactId,
        string $localInvoiceNr,
        string $title,
        float  $amount,
        string $description,
        string $dueDate
    ): ?array {
        $currencyId     = defined('BEXIO_CURRENCY_ID')     ? (int)BEXIO_CURRENCY_ID     : 1;
        $paymentTypeId  = defined('BEXIO_PAYMENT_TYPE_ID') ? (int)BEXIO_PAYMENT_TYPE_ID : 4;
        $accountId      = defined('BEXIO_ACCOUNT_ID')      ? (int)BEXIO_ACCOUNT_ID      : 172;
        $taxId          = defined('BEXIO_TAX_ID')          ? (int)BEXIO_TAX_ID          : 14;
        $userId         = defined('BEXIO_USER_ID')         ? (int)BEXIO_USER_ID         : 1;

        $data = [
            'title'           => $title,
            'contact_id'      => $contactId,
            'contact_sub_id'  => null,
            'user_id'         => $userId,
            'logopaper_id'    => 1,
            'language_id'     => 1,
            'currency_id'     => $currencyId,
            'payment_type_id' => $paymentTypeId,
            'mwst_type'       => 0,
            'mwst_is_net'     => false,
            'show_position_taxes' => false,
            'is_valid_from'   => date('Y-m-d'),
            'is_valid_to'     => $dueDate,
            'reference'       => $localInvoiceNr,
            'api_reference'   => $localInvoiceNr,
            'positions'       => [
                [
                    'type'                => 'KbPositionCustom',
                    'amount'              => '1',
                    'unit_id'             => null,
                    'account_id'          => $accountId,
                    'tax_id'              => $taxId,
                    'text'                => $description,
                    'unit_price'          => number_format($amount, 2, '.', ''),
                    'discount_in_percent' => '0',
                ],
            ],
        ];

        $result = $this->request('POST', '2.0/kb_invoice', $data);

        if (isset($result['_error']) || empty($result['id'])) {
            return null;
        }

        return $result;
    }

    /**
     * Issue (finalize) a Bexio invoice: moves it from draft to issued.
     * This is required before sending.
     */
    public function issueInvoice(int $bexioInvoiceId): bool
    {
        $result = $this->request('POST', "2.0/kb_invoice/{$bexioInvoiceId}/issue");
        return empty($result['_error']);
    }

    /**
     * Send the invoice PDF via email through Bexio.
     */
    public function sendInvoice(int $bexioInvoiceId, string $recipientEmail, string $recipientName = ''): bool
    {
        $data = [
            'recipient_email' => $recipientEmail,
            'subject'         => 'Ihre Rechnung von Company',
            'message'         => "Guten Tag,\n\nanbei finden Sie Ihre Rechnung von Company.\n\nBitte begleichen Sie den Betrag innerhalb der angegebenen Zahlungsfrist.\n\nVielen Dank für Ihre Unterstützung!\n\nFreundliche Grüsse\nTeam Company",
            'mark_as_open'    => true,
        ];

        $result = $this->request('POST', "2.0/kb_invoice/{$bexioInvoiceId}/send", $data);
        return empty($result['_error']);
    }

    /**
     * Fetch a single invoice from Bexio by its ID.
     */
    public function getInvoice(int $bexioInvoiceId): ?array
    {
        $result = $this->request('GET', "2.0/kb_invoice/{$bexioInvoiceId}");

        if (isset($result['_error']) || empty($result['id'])) {
            return null;
        }

        return $result;
    }

    /**
     * Update fields on an existing Bexio invoice (title, contact_address, etc.).
     * Bexio uses POST for updates on the same endpoint.
     */
    public function updateInvoice(int $bexioInvoiceId, array $fields): bool
    {
        $result = $this->request('POST', "2.0/kb_invoice/{$bexioInvoiceId}", $fields);

        if (isset($result['_error']) || empty($result['id'])) {
            error_log('[BexioService::updateInvoice] Failed for #' . $bexioInvoiceId . ': ' . json_encode($result));
            return false;
        }

        return true;
    }

    /**
     * Fetch the PDF of a Bexio invoice.
     *
     * Returns ['name' => '...', 'mime' => 'application/pdf', 'content' => base64] or null.
     */
    public function getInvoicePdf(int $bexioInvoiceId): ?array
    {
        $result = $this->request('GET', "2.0/kb_invoice/{$bexioInvoiceId}/pdf");

        if (isset($result['_error']) || empty($result['content'])) {
            return null;
        }

        return $result;
    }

    /**
     * Create an expense document (Ausgabe / Lieferantenrechnung) in Bexio.
     * Used to record club payouts as outgoing accounting entries.
     * Returns the created document array (including 'document_nr') or null on failure.
     */
    public function createExpense(int $contactId, float $amount, string $title, string $date, string $description = ''): ?array
    {
        $userId = defined('BEXIO_USER_ID') ? (int)BEXIO_USER_ID : 1;
        $data = [
            'contact_id'    => $contactId,
            'title'         => $title,
            'is_valid_from' => $date,
            'is_valid_to'   => date('Y-m-d', strtotime($date . ' +30 days')),
            'currency_id'   => 1, // CHF
            'user_id'       => $userId,
            'positions'     => [
                [
                    'amount'               => '1',
                    'unit_price'           => number_format($amount, 2, '.', ''),
                    'description'          => $description ?: $title,
                    'discount_in_percent'  => '0',
                ],
            ],
        ];

        $result = $this->request('POST', '2.0/kb_expense', $data);
        if (isset($result['_error']) || empty($result['id'])) {
            error_log('[BexioService::createExpense] Failed: ' . json_encode($result));
            return null;
        }
        return $result;
    }


    /**
     * Fetch all bank accounts from Bexio (4.0/banking/accounts).
     * Returns array of ['uuid', 'name', 'iban', 'currency', ...] entries.
     */
    public function getBankAccounts(): array
    {
        $result = $this->request('GET', '4.0/banking/accounts');
        if (isset($result['_error']) || !is_array($result)) {
            return [];
        }
        // v4 returns { results: [...] } or flat array
        return $result['results'] ?? (isset($result[0]) ? $result : []);
    }

    /**
     * Create a bank payment (Zahlungsauftrag) via Bexio 4.0 banking API.
     *
     * @param array $params {
     *   account_uuid  string  UUID of the Bexio sender bank account
     *   recipient_iban string Recipient IBAN (CH or QR-IBAN)
     *   recipient_name string Name of recipient
     *   street_name   string  Recipient street
     *   house_number  string  Recipient house number
     *   zip           string  Recipient ZIP
     *   city          string  Recipient city
     *   country_code  string  Recipient country (e.g. "CH")
     *   amount        float   Amount to transfer
     *   currency      string  Currency (e.g. "CHF")
     *   execution_date string Date in Y-m-d format
     *   message       string  Message / Mitteilung to recipient (stored as additional_information)
     *   is_salary     bool    Whether this is a salary payment
     * }
     * Returns the created payment array or null on failure.
     */
    public function createBankPayment(array $params): ?array
    {
        // Sanitize message: Bexio only allows a specific charset (no em/en dashes etc.)
        $rawMessage = $params['message'] ?? '';
        $message = strtr($rawMessage, [
            '–' => '-', '—' => '-', '«' => '"', '»' => '"',
            '\u2013' => '-', '\u2014' => '-',
        ]);
        // Strip any remaining chars outside the Bexio-allowed pattern
        $message = preg_replace('/[^\x20-\x7EàáâäçèéêëìíîïñòóôöùúûüýßÀÁÂÄÇÈÉÊËÌÍÎÏÒÓÔÖÙÚÛÜÑ]/u', '', $message);
        $message = trim($message) ?: null;

        $data = [
            'account_id'              => $params['account_uuid'],
            'type'                    => 'qr',
            'recipient'               => [
                'iban' => preg_replace('/\s+/', '', $params['recipient_iban']),
                'name' => $params['recipient_name'],
                'address' => [
                    'street_name'  => $params['street_name']  ?? '',
                    'house_number' => $params['house_number'] ?? '',
                    'zip'          => $params['zip']          ?? '',
                    'city'         => $params['city']         ?? '',
                    'country_code' => $params['country_code'] ?? 'CH',
                ],
            ],
            'amount'                  => (float)$params['amount'],
            'currency'                => $params['currency'] ?? 'CHF',
            'execution_date'          => $params['execution_date'],
            'additional_information'  => $message,
            'is_salary'               => (bool)($params['is_salary'] ?? false),
            'allowance'               => '0',
            'qr_reference_number'     => '000000000000000000000000000',
        ];

        $result = $this->request('POST', '4.0/banking/payments', $data);
        if (isset($result['_error']) || empty($result['uuid'])) {
            $errMsg = $result['_error'] ?? json_encode($result);
            error_log('[BexioService::createBankPayment] Failed (' . ($result['_http_code'] ?? '?') . '): ' . $errMsg);
            error_log('[BexioService::createBankPayment] Payload: ' . json_encode($data));
            throw new \RuntimeException('Bexio API ' . ($result['_http_code'] ?? '') . ': ' . $errMsg);
        }
        return $result;
    }

    /**
     * Fetch a single banking payment by UUID.
     * Returns the payment array or null on error.
     */
    public function getBankPayment(string $uuid): ?array
    {
        $result = $this->request('GET', '4.0/banking/payments/' . rawurlencode($uuid));
        if (isset($result['_error']) || empty($result['uuid'])) {
            error_log('[BexioService::getBankPayment] Failed for ' . $uuid . ': ' . json_encode($result));
            return null;
        }
        return $result;
    }

    public function listInvoices(int $offset = 0, int $limit = 500): array
    {
        $result = $this->request('GET', "2.0/kb_invoice?offset={$offset}&limit={$limit}");
        if (isset($result['_error']) || !is_array($result)) {
            return [];
        }
        return isset($result[0]) || empty($result) ? $result : [];
    }

    /**
     * Delete a single invoice from Bexio.
     * Note: Only draft invoices can be deleted; issued invoices must be cancelled first.
     */
    public function deleteInvoice(int $bexioInvoiceId): bool
    {
        $result = $this->request('DELETE', "2.0/kb_invoice/{$bexioInvoiceId}");
        return !isset($result['_error']);
    }

    /**
     * Cancel (stornieren) an issued invoice in Bexio, then delete it.
     * Returns 'deleted', 'cancelled', or 'error'.
     */
    public function cancelAndDeleteInvoice(int $bexioInvoiceId): string
    {
        // Try direct delete first (works for drafts)
        if ($this->deleteInvoice($bexioInvoiceId)) {
            return 'deleted';
        }
        // If delete failed, try to cancel (stornieren) first
        $cancel = $this->request('POST', "2.0/kb_invoice/{$bexioInvoiceId}/cancel");
        if (isset($cancel['_error'])) {
            // 422 = cannot cancel (e.g. already paid) → not a technical error, just a state restriction
            $httpCode = $cancel['_http_code'] ?? 0;
            return ($httpCode === 422 || $httpCode === 403) ? 'cannot_delete' : 'error';
        }
        // Now try delete again
        return $this->deleteInvoice($bexioInvoiceId) ? 'deleted' : 'cancelled';
    }

    /**
     * Book a payment on a Bexio invoice (marks it as paid).
     *
     * Uses POST /2.0/kb_invoice/{id}/payment
     *
     * @param int    $bexioInvoiceId  Bexio invoice ID
     * @param float  $amount          Amount paid
     * @param string $date            Payment date (YYYY-MM-DD), defaults to today
     * @return bool  True on success
     */
    public function bookPayment(int $bexioInvoiceId, float $amount, string $date = ''): bool
    {
        if ($date === '') {
            $date = date('Y-m-d');
        }

        $bankAccountId = defined('BEXIO_BANK_ACCOUNT_ID') ? (int)BEXIO_BANK_ACCOUNT_ID : 1;

        $payload = [
            'bank_account_id' => $bankAccountId,
            'payment_date'    => $date,
            'title'           => 'Zahlungseingang',
            'value'           => $amount,
        ];

        $resp = $this->request('POST', "/2.0/kb_invoice/{$bexioInvoiceId}/payment", $payload);

        if (isset($resp['_error'])) {
            error_log('[BexioService::bookPayment] Failed for invoice ' . $bexioInvoiceId . ': ' . ($resp['_error'] ?? 'unknown'));
            return false;
        }

        return !empty($resp['id']) || !empty($resp['success']);
    }

    /**
     * Map a Bexio invoice status (string name or integer kb_item_status_id) to our internal status.
     *
     * Verified against live API (2026-05):
     *   kb_item_status_id: 8=Open/Pending, 9=Paid, 16=Partial, 17=Paid(legacy), 18=Overdue, 19=Cancelled
     */
    public function mapBexioStatus(string|int $bexioStatus): string
    {
        // Handle numeric status IDs from kb_item_status_id
        if (is_int($bexioStatus) || ctype_digit((string)$bexioStatus)) {
            return match ((int)$bexioStatus) {
                9, 17   => 'paid',
                19      => 'void',
                7       => 'draft',
                8, 16, 18 => 'issued',
                default => 'issued',
            };
        }

        return match (strtolower((string)$bexioStatus)) {
            'paid'      => 'paid',
            'cancelled' => 'void',
            'draft'     => 'draft',
            'pending',
            'open',
            'partial',
            'overdue'   => 'issued',
            default     => 'issued',
        };
    }

    /**
     * Create the full invoice flow: contact + invoice + issue.
     *
     * @return array{
     *   ok:             bool,
     *   bexio_contact_id: int,
     *   bexio_invoice_id: int,
     *   bexio_invoice_nr: string,
     *   error:          string|null
     * }
     */
    public function createFullInvoice(
        array  $buyer,
        string $localInvoiceNr,
        string $title,
        float  $amount,
        string $description,
        string $dueDate
    ): array {
        return $this->createFullInvoiceWithPositions(
            $buyer,
            $localInvoiceNr,
            $title,
            [['text' => $description, 'unit_price' => $amount]],
            $dueDate
        );
    }

    /**
     * Create a full invoice in Bexio with one position per cell/item.
     *
     * @param array  $buyer          Contact info
     * @param string $localInvoiceNr Local invoice number (used as reference)
     * @param string $title          Invoice title
     * @param array  $positions      Array of ['text' => string, 'unit_price' => float]
     * @param string $dueDate        Due date (Y-m-d)
     */
    public function createFullInvoiceWithPositions(
        array  $buyer,
        string $localInvoiceNr,
        string $title,
        array  $positions,
        string $dueDate
    ): array {
        $contactId = $this->searchOrCreateContact($buyer);
        if ($contactId === 0) {
            return ['ok' => false, 'bexio_contact_id' => 0, 'bexio_invoice_id' => 0, 'bexio_invoice_nr' => '', 'error' => 'Could not create/find Bexio contact'];
        }

        $invoice = $this->createInvoiceMultiPosition($contactId, $localInvoiceNr, $title, $positions, $dueDate);
        if ($invoice === null) {
            return ['ok' => false, 'bexio_contact_id' => $contactId, 'bexio_invoice_id' => 0, 'bexio_invoice_nr' => '', 'error' => 'Could not create Bexio invoice'];
        }

        $bexioId = (int)$invoice['id'];
        $bexioNr = (string)($invoice['document_nr'] ?? '');

        $this->issueInvoice($bexioId);

        return [
            'ok'               => true,
            'bexio_contact_id' => $contactId,
            'bexio_invoice_id' => $bexioId,
            'bexio_invoice_nr' => $bexioNr,
            'error'            => null,
        ];
    }

    /**
     * Create a Bexio invoice with multiple positions.
     */
    private function createInvoiceMultiPosition(
        int    $contactId,
        string $localInvoiceNr,
        string $title,
        array  $positions,
        string $dueDate
    ): ?array {
        $currencyId    = defined('BEXIO_CURRENCY_ID')     ? (int)BEXIO_CURRENCY_ID     : 1;
        $paymentTypeId = defined('BEXIO_PAYMENT_TYPE_ID') ? (int)BEXIO_PAYMENT_TYPE_ID : 4;
        $accountId     = defined('BEXIO_ACCOUNT_ID')      ? (int)BEXIO_ACCOUNT_ID      : 172;
        $taxId         = defined('BEXIO_TAX_ID')          ? (int)BEXIO_TAX_ID          : 14;
        $userId        = defined('BEXIO_USER_ID')         ? (int)BEXIO_USER_ID         : 1;

        $bexioPositions = array_values(array_map(fn(array $p) => [
            'type'                => 'KbPositionCustom',
            'amount'              => '1',
            'unit_id'             => null,
            'account_id'          => $accountId,
            'tax_id'              => $taxId,
            'text'                => $p['text'],
            'unit_price'          => number_format((float)$p['unit_price'], 2, '.', ''),
            'discount_in_percent' => '0',
        ], $positions));

        $data = [
            'title'               => $title,
            'contact_id'          => $contactId,
            'contact_sub_id'      => null,
            'user_id'             => $userId,
            'logopaper_id'        => 1,
            'language_id'         => 1,
            'currency_id'         => $currencyId,
            'payment_type_id'     => $paymentTypeId,
            'mwst_type'           => 0,
            'mwst_is_net'         => false,
            'show_position_taxes' => false,
            'is_valid_from'       => date('Y-m-d'),
            'is_valid_to'         => $dueDate,
            'reference'           => $localInvoiceNr,
            'api_reference'       => $localInvoiceNr,
            'positions'           => $bexioPositions,
        ];

        $result = $this->request('POST', '2.0/kb_invoice', $data);

        if (isset($result['_error']) || empty($result['id'])) {
            return null;
        }

        return $result;
    }
}
