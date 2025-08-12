<?php

namespace App\Models\Accounting;

use App\Casts\RateCast;
use App\Collections\Accounting\DocumentCollection;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\BillStatus;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\DocumentType;
use App\Enums\Accounting\JournalEntryType;
use App\Enums\Accounting\TransactionType;
use App\Filament\Company\Resources\Purchases\BillResource;
use App\Models\Banking\BankAccount;
use App\Models\Common\Vendor;
use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Observers\BillObserver;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Filament\Actions\MountableAction;
use Filament\Actions\ReplicateAction;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

#[CollectedBy(DocumentCollection::class)]
#[ObservedBy(BillObserver::class)]
class Bill extends Document
{
    protected $table = 'bills';

    protected $fillable = [
        'company_id',
        'vendor_id',
        'bill_number',
        'order_number',
        'date',
        'due_date',
        'paid_at',
        'status',
        'currency_code',
        'discount_method',
        'discount_computation',
        'discount_rate',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'amount_paid',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'status' => BillStatus::class,
        'discount_method' => DocumentDiscountMethod::class,
        'discount_computation' => AdjustmentComputation::class,
        'discount_rate' => RateCast::class,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    public function payments(): MorphMany
    {
        return $this->transactions()->where('is_payment', true);
    }

    public function deposits(): MorphMany
    {
        return $this->transactions()->where('type', TransactionType::Deposit)->where('is_payment', true);
    }

    public function withdrawals(): MorphMany
    {
        return $this->transactions()->where('type', TransactionType::Withdrawal)->where('is_payment', true);
    }

    public function initialTransaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'transactionable')
            ->where('type', TransactionType::Journal);
    }

    public static function documentType(): DocumentType
    {
        return DocumentType::Bill;
    }

    public function documentNumber(): ?string
    {
        return $this->bill_number;
    }

    public function documentDate(): ?string
    {
        return $this->date?->toDefaultDateFormat();
    }

    public function dueDate(): ?string
    {
        return $this->due_date?->toDefaultDateFormat();
    }

    public function referenceNumber(): ?string
    {
        return $this->order_number;
    }

    public function amountDue(): ?string
    {
        return $this->amount_due;
    }

    public function shouldBeOverdue(): bool
    {
        return $this->due_date->isBefore(company_today()) && $this->canBeOverdue();
    }

    public function wasInitialized(): bool
    {
        return $this->hasInitialTransaction();
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function canBeOverdue(): bool
    {
        return in_array($this->status, BillStatus::canBeOverdue());
    }

    public function canRecordPayment(): bool
    {
        return ! in_array($this->status, [
            BillStatus::Paid,
            BillStatus::Void,
        ]);
    }

    public function hasPayments(): bool
    {
        return $this->payments->isNotEmpty();
    }

    public static function getNextDocumentNumber(?Company $company = null): string
    {
        $company ??= auth()->user()?->currentCompany;

        if (! $company) {
            throw new \RuntimeException('No current company is set for the user.');
        }

        $defaultBillSettings = $company->defaultBill;

        $numberPrefix = $defaultBillSettings->number_prefix ?? '';

        $latestDocument = static::query()
            ->whereNotNull('bill_number')
            ->latest('bill_number')
            ->first();

        $lastNumberNumericPart = $latestDocument
            ? (int) substr($latestDocument->bill_number, strlen($numberPrefix))
            : DocumentDefault::getBaseNumber();

        $numberNext = $lastNumberNumericPart + 1;

        return $defaultBillSettings->getNumberNext(
            prefix: $numberPrefix,
            next: $numberNext
        );
    }

    public function hasInitialTransaction(): bool
    {
        return $this->initialTransaction()->exists();
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BillStatus::Open,
            BillStatus::Partial,
            BillStatus::Overdue,
        ]);
    }

    public function recordPayment(array $data): void
    {
        $transactionType = TransactionType::Withdrawal;
        $transactionDescription = "Bill #{$this->bill_number}: Payment to {$this->vendor->name}";

        // Add multi-currency handling
        $bankAccount = BankAccount::findOrFail($data['bank_account_id']);
        $bankAccountCurrency = $bankAccount->account->currency_code ?? CurrencyAccessor::getDefaultCurrency();

        $billCurrency = $this->currency_code;
        $requiresConversion = $billCurrency !== $bankAccountCurrency;

        // Store the original payment amount in bill currency before any conversion
        $amountInBillCurrencyCents = $data['amount'];

        if ($requiresConversion) {
            $amountInBankCurrencyCents = CurrencyConverter::convertBalance(
                $amountInBillCurrencyCents,
                $billCurrency,
                $bankAccountCurrency
            );
            $formattedAmountForBankCurrency = $amountInBankCurrencyCents;
        } else {
            $formattedAmountForBankCurrency = $amountInBillCurrencyCents;
        }

        // Create transaction with converted amount
        $this->transactions()->create([
            'company_id' => $this->company_id,
            'type' => $transactionType,
            'is_payment' => true,
            'posted_at' => $data['posted_at'],
            'amount' => $formattedAmountForBankCurrency,
            'payment_method' => $data['payment_method'],
            'bank_account_id' => $data['bank_account_id'],
            'account_id' => Account::getAccountsPayableAccount($this->company_id)->id,
            'description' => $transactionDescription,
            'notes' => $data['notes'] ?? null,
            'meta' => [
                'original_document_currency' => $billCurrency,
                'amount_in_document_currency_cents' => $amountInBillCurrencyCents,
            ],
        ]);
    }

    public function createInitialTransaction(?Carbon $postedAt = null): void
    {
        $postedAt ??= $this->date;
        $baseDescription = "{$this->vendor->name}: Bill #{$this->bill_number}";

        $journalEntryData = [];

        $totalInBillCurrency = $this->total;
        $journalEntryData[] = [
            'type' => JournalEntryType::Credit,
            'account_id' => Account::getAccountsPayableAccount($this->company_id)->id,
            'amount_in_bill_currency' => $totalInBillCurrency,
            'description' => $baseDescription,
        ];

        $totalLineItemSubtotalInBillCurrency = (int) $this->lineItems()->sum('subtotal');
        $billDiscountTotalInBillCurrency = (int) $this->discount_total;
        $remainingDiscountInBillCurrency = $billDiscountTotalInBillCurrency;

        foreach ($this->lineItems as $index => $lineItem) {
            $lineItemDescription = "{$baseDescription} › {$lineItem->offering->name}";
            $lineItemSubtotalInBillCurrency = $lineItem->getRawOriginal('subtotal');

            $journalEntryData[] = [
                'type' => JournalEntryType::Debit,
                'account_id' => $lineItem->offering->expense_account_id,
                'amount_in_bill_currency' => $lineItemSubtotalInBillCurrency,
                'description' => $lineItemDescription,
            ];

            foreach ($lineItem->adjustments as $adjustment) {
                $adjustmentAmountInBillCurrency = $lineItem->calculateAdjustmentTotalAmount($adjustment);

                if ($adjustment->isNonRecoverablePurchaseTax()) {
                    $journalEntryData[] = [
                        'type' => JournalEntryType::Debit,
                        'account_id' => $lineItem->offering->expense_account_id,
                        'amount_in_bill_currency' => $adjustmentAmountInBillCurrency,
                        'description' => "{$lineItemDescription} ({$adjustment->name})",
                    ];
                } elseif ($adjustment->account_id) {
                    $journalEntryData[] = [
                        'type' => $adjustment->category->isDiscount() ? JournalEntryType::Credit : JournalEntryType::Debit,
                        'account_id' => $adjustment->account_id,
                        'amount_in_bill_currency' => $adjustmentAmountInBillCurrency,
                        'description' => $lineItemDescription,
                    ];
                }
            }

            // Handle per-document discount allocation
            if ($this->discount_method->isPerDocument() && $totalLineItemSubtotalInBillCurrency > 0) {
                $lineItemSubtotalInBillCurrency = (int) $lineItem->getRawOriginal('subtotal');

                if ($index === $this->lineItems->count() - 1) {
                    $lineItemDiscountInBillCurrency = $remainingDiscountInBillCurrency;
                } else {
                    $lineItemDiscountInBillCurrency = (int) round(
                        ($lineItemSubtotalInBillCurrency / $totalLineItemSubtotalInBillCurrency) * $billDiscountTotalInBillCurrency
                    );
                    $remainingDiscountInBillCurrency -= $lineItemDiscountInBillCurrency;
                }

                if ($lineItemDiscountInBillCurrency > 0) {
                    $journalEntryData[] = [
                        'type' => JournalEntryType::Credit,
                        'account_id' => Account::getPurchaseDiscountAccount($this->company_id)->id,
                        'amount_in_bill_currency' => $lineItemDiscountInBillCurrency,
                        'description' => "{$lineItemDescription} (Proportional Discount)",
                    ];
                }
            }
        }

        // Convert amounts to default currency
        $totalDebitsInDefaultCurrency = 0;
        $totalCreditsInDefaultCurrency = 0;

        foreach ($journalEntryData as &$entry) {
            $entry['amount_in_default_currency'] = $this->formatAmountToDefaultCurrency($entry['amount_in_bill_currency']);

            if ($entry['type'] === JournalEntryType::Debit) {
                $totalDebitsInDefaultCurrency += $entry['amount_in_default_currency'];
            } else {
                $totalCreditsInDefaultCurrency += $entry['amount_in_default_currency'];
            }
        }

        unset($entry);

        // Handle currency conversion imbalance
        $imbalance = $totalDebitsInDefaultCurrency - $totalCreditsInDefaultCurrency;
        if ($imbalance !== 0) {
            $targetType = $imbalance > 0 ? JournalEntryType::Credit : JournalEntryType::Debit;
            $adjustmentAmount = abs($imbalance);

            // Find last entry of target type and adjust it
            $lastKey = array_key_last(array_filter($journalEntryData, fn ($entry) => $entry['type'] === $targetType, ARRAY_FILTER_USE_BOTH));
            $journalEntryData[$lastKey]['amount_in_default_currency'] += $adjustmentAmount;

            if ($targetType === JournalEntryType::Debit) {
                $totalDebitsInDefaultCurrency += $adjustmentAmount;
            } else {
                $totalCreditsInDefaultCurrency += $adjustmentAmount;
            }
        }

        if ($totalDebitsInDefaultCurrency !== $totalCreditsInDefaultCurrency) {
            throw new \Exception('Journal entries do not balance for Bill #' . $this->bill_number . '. Debits: ' . $totalDebitsInDefaultCurrency . ', Credits: ' . $totalCreditsInDefaultCurrency);
        }

        // Create the transaction using the sum of debits
        $transaction = $this->transactions()->create([
            'company_id' => $this->company_id,
            'type' => TransactionType::Journal,
            'posted_at' => $postedAt,
            'amount' => $totalDebitsInDefaultCurrency,
            'description' => 'Bill Creation for Bill #' . $this->bill_number,
        ]);

        // Create all journal entries
        foreach ($journalEntryData as $entry) {
            $transaction->journalEntries()->create([
                'company_id' => $this->company_id,
                'type' => $entry['type'],
                'account_id' => $entry['account_id'],
                'amount' => $entry['amount_in_default_currency'],
                'description' => $entry['description'],
            ]);
        }
    }

    public function updateInitialTransaction(): void
    {
        $this->initialTransaction?->delete();

        $this->createInitialTransaction();
    }

    public function convertAmountToDefaultCurrency(int $amountCents): int
    {
        $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
        $needsConversion = $this->currency_code !== $defaultCurrency;

        if ($needsConversion) {
            return CurrencyConverter::convertBalance($amountCents, $this->currency_code, $defaultCurrency);
        }

        return $amountCents;
    }

    public function formatAmountToDefaultCurrency(int $amountCents): int
    {
        return $this->convertAmountToDefaultCurrency($amountCents);
    }

    public static function getReplicateAction(string $action = ReplicateAction::class): MountableAction
    {
        return $action::make()
            ->excludeAttributes([
                'status',
                'amount_paid',
                'amount_due',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
                'bill_number',
                'date',
                'due_date',
                'paid_at',
            ])
            ->modal(false)
            ->beforeReplicaSaved(function (self $original, self $replica) {
                $replica->status = BillStatus::Open;
                $replica->bill_number = self::getNextDocumentNumber();
                $replica->date = company_today();
                $replica->due_date = company_today()->addDays($original->company->defaultBill->payment_terms->getDays());
            })
            ->databaseTransaction()
            ->after(function (self $original, self $replica) {
                $original->replicateLineItems($replica);
            })
            ->successRedirectUrl(static function (self $replica) {
                return BillResource::getUrl('edit', ['record' => $replica]);
            });
    }

    public function replicateLineItems(Model $target): void
    {
        $this->lineItems->each(function (DocumentLineItem $lineItem) use ($target) {
            $replica = $lineItem->replicate([
                'documentable_id',
                'documentable_type',
                'subtotal',
                'total',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
            ]);

            $replica->documentable_id = $target->id;
            $replica->documentable_type = $target->getMorphClass();
            $replica->save();

            $replica->adjustments()->sync($lineItem->adjustments->pluck('id'));
        });
    }
}
