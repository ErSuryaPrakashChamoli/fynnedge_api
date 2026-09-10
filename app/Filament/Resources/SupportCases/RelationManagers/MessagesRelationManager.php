<?php

namespace App\Filament\Resources\SupportCases\RelationManagers;

use App\Models\AdminUser;
use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use App\Services\Support\SupportCaseService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The conversation on a case, and the one place staff add to it.
 *
 * Replying goes through SupportCaseService::addAdvisorMessage() rather than
 * writing a row here. That method sets `author_type` server-side, records
 * the event, moves an untouched case to acknowledged and notifies the
 * customer through the policy that decides whether it is worth telling
 * them. Creating the message from this class would reproduce four of those
 * five things and quietly forget the fifth.
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Conversation';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('author_type')
                    ->label('From')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        SupportCaseMessage::AUTHOR_CUSTOMER => 'Customer',
                        SupportCaseMessage::AUTHOR_ADVISOR => 'FynnEdge',
                        default => 'System',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        SupportCaseMessage::AUTHOR_CUSTOMER => 'info',
                        SupportCaseMessage::AUTHOR_ADVISOR => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('body')->wrap()->label('Message'),

                TextColumn::make('created_at')->label('Sent')->dateTime()->sortable(),
            ])
            ->headerActions([
                Action::make('reply')
                    ->label('Reply to customer')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn (): bool => $this->canReply())
                    ->schema([
                        Textarea::make('body')
                            ->label('Your reply')
                            ->required()
                            ->minLength(2)
                            ->maxLength(2000)
                            ->rows(5)
                            ->helperText('The customer sees this in the app.'),
                    ])
                    ->action(function (array $data): void {
                        /** @var SupportCase $case */
                        $case = $this->getOwnerRecord();

                        /** @var AdminUser $advisor */
                        $advisor = Auth::guard('admin')->user();

                        app(SupportCaseService::class)
                            ->addAdvisorMessage($case, $advisor, $data['body']);

                        Notification::make()
                            ->success()
                            ->title('Reply sent')
                            ->send();
                    }),
            ])
            // Nothing here is editable or removable. A conversation both
            // sides can see is only worth anything if neither side can
            // revise what was said.
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('id');
    }

    /** Only support roles reply, and only while the case is still live. */
    private function canReply(): bool
    {
        $advisor = Auth::guard('admin')->user();

        if (! $advisor instanceof AdminUser || ! $advisor->role()->handlesSupport()) {
            return false;
        }

        /** @var SupportCase $case */
        $case = $this->getOwnerRecord();

        return ! $case->status()->isTerminal();
    }
}
