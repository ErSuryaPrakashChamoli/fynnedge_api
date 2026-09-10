<?php

namespace App\Filament\Resources\KycVerifications;

use App\Filament\Resources\KycVerifications\Pages\ListKycVerifications;
use App\Filament\Resources\KycVerifications\Pages\ViewKycVerification;
use App\Filament\Resources\KycVerifications\Schemas\KycVerificationInfolist;
use App\Filament\Resources\KycVerifications\Tables\KycVerificationsTable;
use App\Models\KycVerification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Identity verification attempts.
 *
 * Restricted to the roles that may read identity and credit, and stripped
 * even for them. The panel shows whether a check happened and what it
 * concluded — never the identity fields it compared, never the masked
 * reference, never the provider's own record id or the file digest.
 * Knowing a verification succeeded is an operational fact; the contents of
 * somebody's identity document are not.
 */
class KycVerificationResource extends Resource
{
    protected static ?string $model = KycVerification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'KYC & Documents';

    protected static ?string $navigationLabel = 'KYC Verifications';

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return KycVerificationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KycVerificationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKycVerifications::route('/'),
            'view' => ViewKycVerification::route('/{record}'),
        ];
    }
}
