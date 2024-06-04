<?php

namespace App\Filament\App\Resources;

use App\Enums\PriorityEnum;
use App\Filament\App\Resources\TaskResource\Pages;
use App\Filament\App\Resources\TaskResource\RelationManagers;
use App\Livewire\Projects\ListTasks;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Actions;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Livewire;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\Split;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Parallax\FilamentComments\Infolists\Components\CommentsEntry;
use Parallax\FilamentComments\Tables\Actions\CommentsAction;
use Spatie\Activitylog\Models\Activity;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Select::make('client')
                        ->relationship('client', 'name')
                        ->preload()
                        ->live(),
                    Forms\Components\Select::make('project_id')
                        ->label('Project')
                        ->options(function (Forms\Get $get): Collection {
                            $query = Project::orderBy('name');
                            if ($get('client') != null) {
                                $query->where('client_id', $get('client'));
                            }

                            return $query->pluck('name', 'id');
                        }),
                    Forms\Components\Select::make('parent_task')
                        ->relationship('parent', 'title')
                        ->searchable(),
                ]),
                Forms\Components\TextInput::make('title')
                    ->columnSpanFull()
                    ->required(),

                Forms\Components\Section::make('Details')
                    ->collapsible()
                    ->compact()
                    ->schema([
                        Forms\Components\RichEditor::make('description')
                            ->columnSpanFull()
                            ->extraInputAttributes(
                                ['style' => 'max-height: 300px; overflow: scroll'])
                            ->fileAttachmentsDisk('tasks')
                            ->fileAttachmentsDirectory(auth()->user()->company->id)
                            ->fileAttachmentsVisibility('private'),

                        Forms\Components\SpatieTagsInput::make('tags')
                    ]),

                Forms\Components\Grid::make(4)->schema([
                    Forms\Components\Select::make('priority')
                        ->options(PriorityEnum::class)
                        ->default(PriorityEnum::MEDIUM)
                        ->required(),
                    Forms\Components\Select::make('status_id')
                        ->required()
                        ->options(
                            Status::orderBy('sort_order')->pluck('name', 'id')
                        )
                        ->default(Status::orderBy('sort_order')->first()->id),

                    Forms\Components\TextInput::make('effort')
                        ->numeric(),
                    Forms\Components\Select::make('effort_unit')
                        ->options([
                            'h' => 'Hours',
                            'm' => 'Minutes',
                        ])
                        ->default('h'),

                ]),

                Forms\Components\Grid::make(5)->schema([
                    Forms\Components\DatePicker::make('due_date'),

                    Forms\Components\DatePicker::make('planned_start'),
                    Forms\Components\DatePicker::make('planned_end'),
                    Forms\Components\DatePicker::make('actual_start'),
                    Forms\Components\DatePicker::make('actual_end'),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->groups([
                'client.name',
                'project.name',
                'due_date',
                'status.name',
            ])
            ->columns([
                Tables\Columns\IconColumn::make('priority')
                    ->label('')
                    ->sortable()
                    ->icon(fn($state) => PriorityEnum::from($state)->getIcon())
                    ->color(fn($state) => PriorityEnum::from($state)->getColor())
                    ->tooltip(fn($state) => PriorityEnum::from($state)->getLabel()),
                Tables\Columns\TextColumn::make('title')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Medium)
                    ->searchable(),
                Tables\Columns\SelectColumn::make('status_id')
                    ->label('Status')
                    ->options(fn(): array => Status::all()->pluck('name', 'id')->toArray())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\ColorColumn::make('status.color')
                    ->label('')
                    ->tooltip(fn(Model $record) => $record->status->name)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('effort')
                    ->formatStateUsing(fn(Model $record): string => $record->effort . ' ' . $record->effort_unit),
                Tables\Columns\TextColumn::make('planned_start')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('planned_end')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('client.name')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\SpatieTagsColumn::make('tags'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->relationship('status', 'name')
                    ->multiple(true)
                    ->preload(),
                Tables\Filters\SelectFilter::make('client')
                    ->relationship('client', 'name'),
                Tables\Filters\Filter::make('due_date')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('to')
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn(Builder $query, $date): Builder => $query->where('due_date', '>=', $data['from'])
                            )
                            ->when(
                                $data['to'],
                                fn(Builder $query, $date): Builder => $query->where('due_date', '<=', $data['to'])
                            );
                    })
            ], layout: Tables\Enums\FiltersLayout::Modal)
            ->persistFiltersInSession()
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->iconButton(),
                CommentsAction::make()
                    ->iconButton(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Split::make([
                    Grid::make(1)->schema([
                        Section::make([
                            TextEntry::make('title')
                                ->size(TextEntry\TextEntrySize::Large)
                                ->weight(FontWeight::Bold)
                                ->label(''),

                            SpatieTagsEntry::make('tags')
                                ->label(''),

                            Grid::make(2)->schema([
                                TextEntry::make('project.name')
                                    ->label('')
                                    ->icon('heroicon-o-presentation-chart-bar'),

                                TextEntry::make('client.name')
                                    ->label('')
                                    ->icon('heroicon-o-building-office'),
                            ]),

                            TextEntry::make('description')
                                ->formatStateUsing(fn(string $state): HtmlString => new HtmlString($state))
                                ->icon('heroicon-o-document-text'),

                            Grid::make(4)->schema([
                                TextEntry::make('planned_start')
                                    ->date()
                                    ->icon('heroicon-o-calendar'),
                                TextEntry::make('planned_end')
                                    ->date()
                                    ->icon('heroicon-o-calendar'),

                                TextEntry::make('actual_start')
                                    ->date()
                                    ->icon('heroicon-o-calendar'),

                                TextEntry::make('actual_end')
                                    ->date()
                                    ->icon('heroicon-o-calendar'),
                            ]),
                        ]),

                        Section::make('Comments')
                            ->collapsible()
                            ->schema([
                                CommentsEntry::make('fialament_comments')
                                    ->columnSpanFull(),
                            ]),

                        Section::make('History')
                            ->collapsible()
                            ->persistCollapsed()
                            ->schema([
                                RepeatableEntry::make('activities')
                                    ->label('')
                                    ->columnSpanFull()
                                    ->schema([
                                        Grid::make(4)->schema([
                                            TextEntry::make('created_at')
                                                ->label('')
                                                ->dateTime(),
                                            TextEntry::make('description')
                                                ->label(''),
                                            TextEntry::make('causer.name')
                                                ->label(''),
                                            TextEntry::make('properties.attributes')
                                                ->label('')
                                                ->getStateUsing(
                                                    fn(Model $record): array => [$record->properties->get('attributes')['status.name']]
                                                )
                                        ])
                                    ])
                            ])
                    ]),

                    Section::make([
                        Grid::make(2)->schema([
                            IconEntry::make('priority')
                                ->icon(fn($state) => PriorityEnum::from($state)->getIcon())
                                ->color(fn($state) => PriorityEnum::from($state)->getColor())
                                ->tooltip(fn($state) => PriorityEnum::from($state)->getLabel()),

                            TextEntry::make('status.name')
                                ->badge()
                                ->color(fn(Model $record): array => Color::hex($record->status->color)),
                        ]),

                        TextEntry::make('due_date')
                            ->date()
                            ->icon('heroicon-o-exclamation-triangle'),

                        TextEntry::make('effort')
                            ->label('Effort')
                            ->inlineLabel()
                            ->icon('heroicon-o-calculator')
                            ->formatStateUsing(fn(Model $record): string => $record->effort . ' ' . $record->effort_unit),

                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                        Actions::make([
                            Action::make('edit')
                                ->url(
                                    fn(Model $record) => "{$record->id}/edit"
                                )
                                ->icon('heroicon-o-pencil'),

                            Action::make('back')
                                ->url(
                                    fn(Model $record) => "./."
                                )
                                ->icon('heroicon-o-chevron-left')
                                ->color(Color::Neutral),
                        ])
                    ])->grow(false),


                ])
                    ->columnSpanFull(),

            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'view' => Pages\ViewTask::route('/{record}'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }
}
