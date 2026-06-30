<?php
/**
 * BRYGAD ERP - Mapper danych Fakturownia
 *
 * Tłumaczy struktury ERP na payloady API Fakturowni
 * oraz mapuje odpowiedzi API na pola lokalne ERP.
 */

class FakturowniaMapper
{
    public function contractClientToFakturownia(array $erpClient): array
    {
        $address = $this->parseAddress((string)($erpClient['address'] ?? ''));

        return [
            'client' => [
                'name' => trim((string)($erpClient['name'] ?? '')),
                'tax_no' => $this->cleanNip((string)($erpClient['nip'] ?? '')),
                'street' => $address['street'],
                'city' => $address['city'],
                'post_code' => $address['post_code'],
                'email' => trim((string)($erpClient['email'] ?? '')),
                'phone' => trim((string)($erpClient['phone'] ?? '')),
                'person' => trim((string)($erpClient['contact_person'] ?? '')),
            ],
        ];
    }

    public function milestoneToInvoice(array $erpMilestone, array $erpContract): array
    {
        // Kolumna w project_revenues to amount_net (nie net_amount).
        // Pola vat_rate, quantity, unit, service_date, issue_date nie istnieją
        // w project_revenues — używamy bezpiecznych domyślnych wartości.
        $netAmount = (float)($erpMilestone['amount_net'] ?? 0);
        $vatRate = $this->normalizeVatRate($erpMilestone['vat_rate'] ?? '23');
        $paymentDays = (int)($erpContract['payment_days'] ?? 14);
        $quantity = max(1.0, (float)($erpMilestone['quantity'] ?? 1));
        $priceNet = $netAmount / $quantity;
        $unit = trim((string)($erpMilestone['unit'] ?? 'szt'));

        $sellDate = $erpMilestone['service_date']
            ?? $erpMilestone['signed_date']
            ?? date('Y-m-d');
        $issueDate = $erpMilestone['issue_date']
            ?? $erpMilestone['signed_date']
            ?? date('Y-m-d');

        $vatMultiplier = ($vatRate === 'zw') ? 1 : (1 + (float)$vatRate / 100);
        $totalPriceGross = round($quantity * $priceNet * $vatMultiplier, 2);

        return [
            'invoice' => [
                'kind' => 'vat',
                'sell_date' => $this->normalizeDate($sellDate),
                'issue_date' => $this->normalizeDate($issueDate),
                'payment_to_kind' => $paymentDays,
                'payment_type' => trim((string)($erpContract['payment_method'] ?? 'transfer')),
                'currency' => trim((string)($erpContract['currency'] ?? 'PLN')),
                'buyer_name' => trim((string)($erpContract['client_name'] ?? '')),
                'buyer_tax_no' => $this->cleanNip((string)($erpContract['client_nip'] ?? '')),
                'positions' => [
                    [
                        'name' => trim((string)($erpMilestone['name'] ?? 'Pozycja faktury')),
                        'quantity' => $quantity,
                        'tax' => $vatRate,
                        'total_price_gross' => $totalPriceGross,
                        'quantity_unit' => $unit !== '' ? $unit : 'szt',
                    ],
                ],
            ],
        ];
    }

    /**
     * Buduje payload faktury z lokalnego modułu faktur sprzedażowych ERP.
     *
     * @param array $invoice          Nagłówek z invoices_sale + pola klienta
     * @param array $items            Pozycje z invoice_sale_items
     * @param int|null $correctionFakturowniaId  ID faktury korygowanej w Fakturowni (wymagane dla kind=correction)
     */
    public function invoiceSaleToInvoice(array $invoice, array $items, ?int $correctionFakturowniaId = null): array
    {
        $invoiceOptions = $this->decodeJsonOptions($invoice['fakturownia_options_json'] ?? null);
        $invoiceKind = $this->normalizeInvoiceKind((string)($invoiceOptions['kind'] ?? 'vat'));
        $isCorrection = $invoiceKind === 'correction';
        $discountKind = $this->normalizeDiscountKind((string)($invoiceOptions['discount_kind'] ?? 'none'));
        $showDiscount = $discountKind !== 'none';

        $positions = [];
        foreach ($items as $item) {
            $itemOptions = $this->decodeJsonOptions($item['fakturownia_item_options_json'] ?? null);

            $quantity = (float)($item['quantity'] ?? 1);
            $unit = trim((string)($item['unit'] ?? 'szt'));
            $vatRate = $this->normalizeVatRate($item['vat_rate'] ?? '23');
            $priceNet = round((float)($item['unit_price_net'] ?? 0), 2);
            $name = trim((string)($item['item_name'] ?? 'Pozycja faktury'));
            $unit = $unit !== '' ? $unit : 'szt';

            if ($isCorrection) {
                // Fakturownia wymaga correction_before_attributes i correction_after_attributes
                $before = is_array($itemOptions['correction_before'] ?? null) ? $itemOptions['correction_before'] : null;

                if ($before) {
                    $beforeQty        = (float)($before['quantity'] ?? $quantity);
                    $beforePriceNet   = round((float)($before['unit_price_net'] ?? $priceNet), 2);
                    $beforeVat        = $this->normalizeVatRate($before['vat_rate'] ?? $item['vat_rate'] ?? '23');
                    $beforeName       = trim((string)($before['name'] ?? $name));
                    $beforeNet        = round((float)($before['amount_net'] ?? ($beforeQty * $beforePriceNet)), 2);
                    $beforeGross      = round((float)($before['amount_gross'] ?? $this->grossFromNet($beforeNet, $beforeVat)), 2);
                    $beforeVatAmount  = round($beforeGross - $beforeNet, 2);
                    $beforePriceGross = $beforeQty != 0.0 ? round($beforeGross / $beforeQty, 2) : $beforeGross;
                } else {
                    // Brak danych "przed" — cała pozycja traktowana jako nowa (korekta do zera i nowa wartość)
                    $beforeQty        = $quantity;
                    $beforePriceNet   = $priceNet;
                    $beforeVat        = $vatRate;
                    $beforeName       = $name;
                    $beforeNet        = round((float)($item['amount_net'] ?? ($quantity * $priceNet)), 2);
                    $beforeGross      = round((float)($item['amount_gross'] ?? $this->grossFromNet($beforeNet, $beforeVat)), 2);
                    $beforeVatAmount  = round($beforeGross - $beforeNet, 2);
                    $beforePriceGross = $beforeQty != 0.0 ? round($beforeGross / $beforeQty, 2) : $beforeGross;
                }

                $afterNet        = round((float)($item['amount_net'] ?? ($quantity * $priceNet)), 2);
                $afterGross      = round((float)($item['amount_gross'] ?? $this->grossFromNet($afterNet, $vatRate)), 2);
                $afterVatAmount  = round($afterGross - $afterNet, 2);
                $afterPriceGross = $quantity != 0.0 ? round($afterGross / $quantity, 2) : $afterGross;
                $diffNet         = round($afterNet - $beforeNet, 2);
                $diffGross       = round($afterGross - $beforeGross, 2);
                $diffVatAmount   = round($diffGross - $diffNet, 2);
                $diffQty         = round($quantity - $beforeQty, 4);
                $diffPriceNet    = $diffQty != 0.0 ? round($diffNet / $diffQty, 2) : 0;
                $diffPriceGross  = $diffQty != 0.0 ? round($diffGross / $diffQty, 2) : 0;

                $position = [
                    'name'              => $name,
                    'quantity'          => $diffQty,
                    'price_net'         => $this->moneyString($diffPriceNet),
                    'price_gross'       => $this->moneyString($diffPriceGross),
                    'total_price_net'   => $this->moneyString($diffNet),
                    'total_price_tax'   => $this->moneyString($diffVatAmount),
                    'total_price_gross' => $this->moneyString($diffGross),
                    'tax'               => $vatRate,
                    'kind'              => 'correction',
                    'correction_before_attributes' => [
                        'name'              => $beforeName,
                        'quantity'          => (string)$beforeQty,
                        'price_net'         => $this->moneyString($beforePriceNet),
                        'price_gross'       => $this->moneyString($beforePriceGross),
                        'total_price_net'   => $this->moneyString($beforeNet),
                        'total_price_tax'   => $this->moneyString($beforeVatAmount),
                        'total_price_gross' => $this->moneyString($beforeGross),
                        'tax'               => $beforeVat,
                        'kind'              => 'correction_before',
                    ],
                    'correction_after_attributes' => [
                        'name'              => $name,
                        'quantity'          => (string)$quantity,
                        'price_net'         => $this->moneyString($priceNet),
                        'price_gross'       => $this->moneyString($afterPriceGross),
                        'total_price_net'   => $this->moneyString($afterNet),
                        'total_price_tax'   => $this->moneyString($afterVatAmount),
                        'total_price_gross' => $this->moneyString($afterGross),
                        'tax'               => $vatRate,
                        'kind'              => 'correction_after',
                    ],
                ];

                $productId = (int)($itemOptions['product_id'] ?? 0);
                if ($productId > 0) {
                    $position['product_id'] = $productId;
                }

                $positions[] = $position;
                continue;
            }

            // Standardowa pozycja (nie-korekta)
            $quantity = max(1.0, $quantity);
            $totalPriceGross = round((float)($item['amount_gross'] ?? 0), 2);
            if ($totalPriceGross <= 0) {
                $vatMultiplier = ($vatRate === 'zw') ? 1 : (1 + (float)$vatRate / 100);
                $totalPriceGross = round($quantity * $priceNet * $vatMultiplier, 2);
            }
            $position = [
                'name'              => $name,
                'quantity'          => $quantity,
                'tax'               => $vatRate,
                'total_price_gross' => $totalPriceGross,
                'quantity_unit'     => $unit,
            ];

            $productId = (int)($itemOptions['product_id'] ?? 0);
            if ($productId > 0) {
                $position['product_id'] = $productId;
            }

            $gtuCode = $this->normalizeGtuCode((string)($itemOptions['gtu_code'] ?? ''));
            if ($gtuCode !== '') {
                $position['gtu_code'] = $gtuCode;
            }

            if ($showDiscount && $discountKind === 'percent_unit') {
                $discountPercent = (float)($itemOptions['discount_percent'] ?? 0);
                if ($discountPercent > 0) {
                    $position['discount_percent'] = round(min(100, max(0, $discountPercent)), 2);
                }
            }

            if ($showDiscount && $discountKind === 'amount') {
                $discountAmount = (float)($itemOptions['discount'] ?? 0);
                if ($discountAmount > 0) {
                    $position['discount'] = round(max(0, $discountAmount), 2);
                }
            }

            $positions[] = $position;
        }

        $payloadInvoice = [
            'kind' => $invoiceKind,
            'number' => null, // Numer nadaje Fakturownia
            'sell_date' => $this->normalizeDate($invoice['sale_date'] ?? null),
            'issue_date' => $this->normalizeDate($invoice['issue_date'] ?? null),
            'payment_to' => $this->normalizeDate($invoice['due_date'] ?? null),
            'payment_to_kind' => (int)($invoice['payment_days'] ?? 14),
            'payment_type' => $this->mapPaymentType((string)($invoiceOptions['payment_type_override'] ?? ($invoice['payment_method'] ?? 'transfer'))),
            'currency' => strtoupper(trim((string)($invoice['currency'] ?? 'PLN'))) ?: 'PLN',
            'buyer_name' => trim((string)($invoice['client_name'] ?? '')),
            'buyer_tax_no' => $this->cleanNip((string)($invoice['client_nip'] ?? '')),
            'buyer_company' => true,
            'positions' => $positions,
        ];

        $this->applySellerBankAccount($payloadInvoice, $invoice);

        $placeOfIssue = trim((string)($invoice['place_of_issue'] ?? ''));
        if ($placeOfIssue !== '') {
            $payloadInvoice['place'] = $placeOfIssue;
        }

        // Pola specyficzne dla faktury korygującej
        if ($isCorrection) {
            // Fakturownia przelicza payment_to od issue_date, jeśli dostanie payment_to_kind.
            // Dla korekty termin ma zostać twardo z dokumentu pierwotnego.
            unset($payloadInvoice['payment_to_kind']);

            $correctionReason = trim((string)($invoiceOptions['correction_reason'] ?? ''));
            $payloadInvoice['correction_reason'] = $correctionReason !== '' ? $correctionReason : 'Korekta';

            $correctedContentBefore = trim((string)($invoiceOptions['corrected_content_before'] ?? ''));
            if ($correctedContentBefore !== '') {
                $payloadInvoice['corrected_content_before'] = $correctedContentBefore;
            }

            $correctedContentAfter = trim((string)($invoiceOptions['corrected_content_after'] ?? ''));
            if ($correctedContentAfter !== '') {
                $payloadInvoice['corrected_content_after'] = $correctedContentAfter;
            }

            if ($correctionFakturowniaId !== null && $correctionFakturowniaId > 0) {
                $payloadInvoice['invoice_id']      = $correctionFakturowniaId;
                $payloadInvoice['from_invoice_id'] = $correctionFakturowniaId;
            }
        }

        $departmentId = (int)($invoiceOptions['department_id'] ?? 0);
        if ($departmentId > 0) {
            $payloadInvoice['department_id'] = $departmentId;
        }

        $categoryId = (int)($invoiceOptions['category_id'] ?? 0);
        if ($categoryId > 0) {
            $payloadInvoice['category_id'] = $categoryId;
        }

        $lang = $this->normalizeLang((string)($invoiceOptions['lang'] ?? 'pl'));
        if ($lang !== '') {
            $payloadInvoice['lang'] = $lang;
        }

        $buyerEmail = trim((string)($invoiceOptions['buyer_email'] ?? ''));
        if ($buyerEmail !== '') {
            $payloadInvoice['buyer_email'] = $buyerEmail;
        }

        $oid = trim((string)($invoiceOptions['oid'] ?? ''));
        if ($oid !== '') {
            $payloadInvoice['oid'] = $oid;
        }

        // Pole "Uwagi" z faktury (notes) trafia do description; opcja zaawansowana description ma pierwszeństwo
        $notes = trim((string)($invoice['notes'] ?? ''));
        $description = trim((string)($invoiceOptions['description'] ?? ''));
        $finalDescription = $description !== '' ? $description : $notes;
        if ($finalDescription !== '') {
            $payloadInvoice['description'] = $finalDescription;
        }

        $descriptionFooter = trim((string)($invoiceOptions['description_footer'] ?? ''));
        if ($descriptionFooter !== '') {
            $payloadInvoice['description_footer'] = $descriptionFooter;
        }

        $descriptionLong = trim((string)($invoiceOptions['description_long'] ?? ''));
        if ($descriptionLong !== '') {
            $payloadInvoice['description_long'] = $descriptionLong;
        }

        $exchangeCurrency = strtoupper(trim((string)($invoiceOptions['exchange_currency'] ?? '')));
        if ($exchangeCurrency !== '') {
            $payloadInvoice['exchange_currency'] = $exchangeCurrency;
            $payloadInvoice['exchange_kind'] = trim((string)($invoiceOptions['exchange_kind'] ?? 'nbp')) ?: 'nbp';

            $exchangeRate = (float)($invoiceOptions['exchange_rate'] ?? 0);
            if ($exchangeRate > 0) {
                $payloadInvoice['exchange_rate'] = round($exchangeRate, 6);
            }
        }

        if ($showDiscount) {
            $payloadInvoice['show_discount'] = true;
            $payloadInvoice['discount_kind'] = $discountKind;
        }

        if (!empty($invoice['split_payment'])) {
            $payloadInvoice['split_payment'] = true;
        }

        return ['invoice' => $payloadInvoice];
    }

    public function fakturowniaResponseToErp(array $apiResponse): array
    {
        $invoice = $apiResponse['invoice'] ?? $apiResponse;

        return [
            'fakturownia_id' => isset($invoice['id']) ? (int)$invoice['id'] : null,
            'fakturownia_number' => $invoice['number'] ?? null,
            'gov_id' => $invoice['gov_id'] ?? null,
            'gov_status' => $this->normalizeGovStatus($invoice['gov_status'] ?? null),
            'status' => $this->mapInvoiceStatus($invoice),
            'pdf_url' => $invoice['pdf_url'] ?? null,
            'synced_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function fakturowniaKsefStatusToErp(array $apiResponse): array
    {
        $invoice = $apiResponse['invoice'] ?? $apiResponse;
        $govStatus = $this->normalizeGovStatus($invoice['gov_status'] ?? null);

        $errors = [];
        if (!empty($invoice['gov_error_messages']) && is_array($invoice['gov_error_messages'])) {
            $errors = $invoice['gov_error_messages'];
        }

        return [
            'gov_id' => $invoice['gov_id'] ?? null,
            'gov_status' => $govStatus,
            'gov_error_messages' => $errors,
            'status' => $this->mapInvoiceStatus($invoice),
            'synced_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function parseAddress(string $fullAddress): array
    {
        $fullAddress = trim($fullAddress);
        if ($fullAddress === '') {
            return ['street' => '', 'city' => '', 'post_code' => ''];
        }

        $street = $fullAddress;
        $city = '';
        $postCode = '';

        // Próbujemy wyciągnąć "ulica, 00-000 Miasto"
        if (preg_match('/^(.*),\s*([0-9]{2}-[0-9]{3})\s+(.+)$/u', $fullAddress, $m)) {
            $street = trim($m[1]);
            $postCode = trim($m[2]);
            $city = trim($m[3]);
        }

        return [
            'street' => $street,
            'city' => $city,
            'post_code' => $postCode,
        ];
    }

    private function cleanNip(string $nip): string
    {
        return preg_replace('/[^0-9]/', '', $nip);
    }

    private function normalizeDate($date): string
    {
        $date = trim((string)$date);
        if ($date === '') {
            return date('Y-m-d');
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return date('Y-m-d');
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeVatRate($vatRate)
    {
        $vatRate = trim((string)$vatRate);
        if ($vatRate === '' || strtolower($vatRate) === 'zw') {
            return $vatRate === '' ? '23' : 'zw';
        }

        if (is_numeric($vatRate)) {
            return (string)(int)$vatRate;
        }

        return '23';
    }

    private function normalizeGovStatus($govStatus): string
    {
        $status = strtolower(trim((string)$govStatus));
        if ($status === 'ok' || $status === 'accepted') {
            return 'ok';
        }

        if ($status === 'error' || $status === 'rejected') {
            return 'error';
        }

        return 'pending';
    }

    private function grossFromNet(float $net, $vatRate): float
    {
        if ((string)$vatRate === 'zw') {
            return round($net, 2);
        }

        return round($net * (1 + ((float)$vatRate / 100)), 2);
    }

    private function moneyString(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private function mapInvoiceStatus(array $invoice): string
    {
        $status = strtolower((string)($invoice['status'] ?? $invoice['state'] ?? 'draft'));

        $paidAmount = (float)str_replace(',', '.', (string)($invoice['paid'] ?? '0'));

        if (in_array($status, ['paid', 'zaplacona'], true)) {
            return 'paid';
        }

        if (in_array($status, ['partial'], true) || ($paidAmount > 0 && !in_array($status, ['paid', 'zaplacona'], true))) {
            return 'partially_paid';
        }

        if (in_array($status, ['sent', 'issued', 'wystawiona'], true)) {
            return 'sent';
        }

        return 'draft';
    }

    private function mapPaymentType(string $paymentMethod): string
    {
        $method = strtolower(trim($paymentMethod));
        $map = [
            'transfer' => 'transfer',
            'przelew' => 'transfer',
            'cash' => 'cash',
            'gotowka' => 'cash',
            'card' => 'card',
            'karta' => 'card',
            'paypal' => 'paypal',
            'payu' => 'payu',
            'dotpay' => 'dotpay',
            'bliks' => 'bliks',
            'p24' => 'przelewy24',
            'przelewy24' => 'przelewy24',
            'credit' => 'credit',
            'debit' => 'debit',
            'other' => 'transfer',
        ];

        if (isset($map[$method])) {
            return $map[$method];
        }

        return 'transfer';
    }

    private function decodeJsonOptions($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function applySellerBankAccount(array &$payloadInvoice, array $invoice): void
    {
        $bankAccount = trim((string)($invoice['bank_account'] ?? ''));
        if ($bankAccount === '') {
            return;
        }

        $sellerData = $this->decodeJsonOptions($invoice['seller_data_json'] ?? null);
        $bankName = trim((string)($sellerData['bank_name'] ?? ''));
        $currency = strtoupper(trim((string)($invoice['currency'] ?? 'PLN'))) ?: 'PLN';

        // Fakturownia now uses bank_accounts[] for explicit invoice bank accounts.
        // seller_bank_account/seller_bank are kept for compatibility with older API fields.
        $bankAccountPayload = [
            'bank_account_number' => $bankAccount,
            'bank_currency' => $currency,
        ];

        if ($bankName !== '') {
            $bankAccountPayload['bank_name'] = $bankName;
            $payloadInvoice['seller_bank'] = $bankName;
        }

        $payloadInvoice['seller_bank_account'] = $bankAccount;
        $payloadInvoice['bank_accounts'] = [$bankAccountPayload];
    }

    private function normalizeInvoiceKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        $allowed = ['vat', 'proforma', 'bill', 'receipt', 'advance', 'final', 'correction', 'vat_mp', 'vat_margin'];
        return in_array($kind, $allowed, true) ? $kind : 'vat';
    }

    private function normalizeLang(string $lang): string
    {
        $lang = strtolower(trim($lang));
        $allowed = ['pl', 'en', 'de', 'fr', 'it', 'es', 'cs', 'sk'];
        return in_array($lang, $allowed, true) ? $lang : 'pl';
    }

    private function normalizeDiscountKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if (in_array($kind, ['percent_unit', 'amount'], true)) {
            return $kind;
        }
        return 'none';
    }

    private function normalizeGtuCode(string $gtuCode): string
    {
        $gtuCode = strtoupper(trim($gtuCode));
        if ($gtuCode === '') {
            return '';
        }

        if (preg_match('/^GTU_(0[1-9]|1[0-3])$/', $gtuCode)) {
            return $gtuCode;
        }

        return '';
    }

    /**
     * Buduje payload faktury JST (KSeF Podmiot2/Podmiot3).
     *
     * Rozszerza standardowy invoiceSaleToInvoice() o:
     *  - buyer_jst = "1" (Podmiot2 — nabywca JST, np. Miasto Poznań)
     *  - dane buyer_* nadpisane danymi JST
     *  - recipient_* (Podmiot3 — odbiorca, np. POSiR)
     *  - recipients[] z role "JST – odbiorca" wymaganą przez KSeF
     *
     * @param array    $invoice              Nagłówek z invoices_sale + pola klienta
     * @param array    $items                Pozycje z invoice_sale_items
     * @param array    $jstData              Wiersz z invoice_sale_jst_data
     * @param int|null $correctionFakturowniaId  ID faktury korygowanej w Fakturowni
     */
    public function invoiceSaleToInvoiceJst(array $invoice, array $items, array $jstData, ?int $correctionFakturowniaId = null): array
    {
        $payload = $this->invoiceSaleToInvoice($invoice, $items, $correctionFakturowniaId);

        // Podmiot2 — JST nabywca (nadpisujemy pola buyer_*)
        $payload['invoice']['buyer_jst']       = '1';
        $payload['invoice']['buyer_name']      = trim((string)($jstData['jst_buyer_name'] ?? ''));
        $payload['invoice']['buyer_tax_no']    = $this->cleanNip((string)($jstData['jst_buyer_nip'] ?? ''));
        $payload['invoice']['buyer_street']    = trim((string)($jstData['jst_buyer_street'] ?? ''));
        $payload['invoice']['buyer_post_code'] = trim((string)($jstData['jst_buyer_post_code'] ?? ''));
        $payload['invoice']['buyer_city']      = trim((string)($jstData['jst_buyer_city'] ?? ''));

        // Podmiot3 — Odbiorca (np. POSiR) — tylko gdy podano nazwę
        $recipientName = trim((string)($jstData['jst_recipient_name'] ?? ''));
        if ($recipientName !== '') {
            $recipientNip      = $this->cleanNip((string)($jstData['jst_recipient_nip'] ?? ''));
            $recipientStreet   = trim((string)($jstData['jst_recipient_street'] ?? ''));
            $recipientPostCode = trim((string)($jstData['jst_recipient_post_code'] ?? ''));
            $recipientCity     = trim((string)($jstData['jst_recipient_city'] ?? ''));
            $recipientNote     = trim((string)($jstData['jst_recipient_note'] ?? ''));

            // Pola płaskie recipient_* (kompatybilność wsteczna z Fakturownia)
            $payload['invoice']['recipient_name']      = $recipientName;
            $payload['invoice']['recipient_tax_no']    = $recipientNip;
            $payload['invoice']['recipient_street']    = $recipientStreet;
            $payload['invoice']['recipient_post_code'] = $recipientPostCode;
            $payload['invoice']['recipient_city']      = $recipientCity;
            if ($recipientNote !== '') {
                $payload['invoice']['recipient_note'] = $recipientNote;
            }

            // Tablica recipients[] z rolą KSeF "JST – odbiorca"
            $recipientEntry = [
                'name'    => $recipientName,
                'company' => true,
                'role'    => 'JST – odbiorca',
            ];
            if ($recipientNip !== '') {
                $recipientEntry['tax_no'] = $recipientNip;
            }
            if ($recipientStreet !== '') {
                $recipientEntry['street'] = $recipientStreet;
            }
            if ($recipientPostCode !== '') {
                $recipientEntry['post_code'] = $recipientPostCode;
            }
            if ($recipientCity !== '') {
                $recipientEntry['city'] = $recipientCity;
            }

            $payload['invoice']['recipients'] = [$recipientEntry];
        }

        return $payload;
    }
}
