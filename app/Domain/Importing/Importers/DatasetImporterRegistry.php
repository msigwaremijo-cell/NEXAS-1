<?php

namespace App\Domain\Importing\Importers;

use App\Domain\Importing\Enums\ImportDataset;

/**
 * The importer a dataset is read with.
 *
 * One place maps each dataset to the class that knows how to read it, so
 * nothing else has to hold a match over the six of them. Every dataset has an
 * importer, so the lookup cannot miss: adding a dataset without an importer is
 * caught here, once, rather than in whichever caller happened to ask for it.
 */
final class DatasetImporterRegistry
{
    /**
     * @var array<string, DatasetImporter>
     */
    private readonly array $importers;

    public function __construct(
        ChartOfAccountsImporter $chartOfAccounts,
        CustomerImporter $customers,
        SupplierImporter $suppliers,
        ProductImporter $products,
        OpeningStockImporter $openingStock,
        OpeningBalanceImporter $openingBalances,
    ) {
        $this->importers = [
            ImportDataset::ChartOfAccounts->value => $chartOfAccounts,
            ImportDataset::Customers->value => $customers,
            ImportDataset::Suppliers->value => $suppliers,
            ImportDataset::Products->value => $products,
            ImportDataset::OpeningStock->value => $openingStock,
            ImportDataset::OpeningBalances->value => $openingBalances,
        ];
    }

    public function for(ImportDataset $dataset): DatasetImporter
    {
        return $this->importers[$dataset->value];
    }
}
